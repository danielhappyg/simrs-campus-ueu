<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceAccommodationTariffBinding;
use App\Models\FinanceAccommodationTariffBindingVersion;
use App\Models\FinanceAccommodationTariffOperationReceipt;
use App\Models\FinanceCostComponent;
use App\Models\FinanceCostComponentGroup;
use App\Models\FinanceTariffCatalogue;
use App\Models\FinanceTariffItem;
use App\Models\InpatientBed;
use App\Models\InpatientBedVersion;
use App\Models\InpatientWard;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Finance\FinanceAccommodationTariffAppendOnlyGuard;
use App\Support\Finance\FinanceAccommodationTariffBindingService;
use App\Support\Finance\FinanceAccommodationTariffProjection;
use App\Support\Finance\FinanceAccommodationTariffSqlWriteGuard;
use App\Support\Finance\FinanceDenied;
use App\Support\Finance\FinanceTariffDenied;
use App\Support\Finance\FinanceTariffMasterService;
use App\Support\Finance\FinanceTariffSchemaMutationScope;
use App\Support\Inpatient\InpatientMasterService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class FinanceAccommodationTariffCoreTest extends TestCase
{
    use RefreshDatabase;

    private User $steward;

    private InpatientBedVersion $bedVersion;

    private FinanceTariffItem $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->steward = $this->actor(RoleCapabilityMatrix::ROLE_FINANCE_STEWARD);
        [$this->bedVersion, $this->tariff] = $this->upstreams();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_schema_has_exact_binding_and_fourth_typed_source_contract(): void
    {
        foreach (['finance_accommodation_tariff_bindings', 'finance_accommodation_tariff_binding_versions', 'finance_accommodation_tariff_operation_receipts', 'finance_accommodation_source_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('finance_accommodation_tariff_bindings', [
            'inpatient_bed_version_id', 'inpatient_bed_version_public_id', 'inpatient_bed_version', 'inpatient_bed_content_digest', 'pricing_unit',
        ]));
        $this->assertTrue(Schema::hasColumn('finance_charge_events', 'finance_accommodation_source_event_id'));
        $source = file_get_contents(database_path('migrations/2026_09_02_000600_create_inpatient_accommodation_tariff_source.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("source_domain='ACCOMMODATION'", $source);
        $this->assertStringContainsString("pricing_unit='OCCUPANCY_DAY'", $source);
        $this->assertStringContainsString("closing_type='BED_TRANSFER'", $source);
        $this->assertStringContainsString("closing_type='ROUTINE_DISCHARGE'", $source);
        $this->assertStringContainsString("['pgsql', 'mysql']", $source);
        $this->assertStringContainsString('DROP CHECK fce_value_ck', $source);
        $this->assertStringContainsString('qualifyPostgresSequences', $source);
        $this->assertStringContainsString('FinanceAccommodationTariffAppendOnlyGuard::install()', $source);
    }

    public function test_binding_replay_history_resolution_and_terminal_retirement_are_exact(): void
    {
        $service = $this->service();
        $createdResult = $service->create(
            $this->steward, $this->bedVersion->public_id, 'INPATIENT', $this->tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'accommodation-create-0001',
        );
        $created = $createdResult->record;
        $replay = $service->create(
            $this->steward, $this->bedVersion->public_id, 'INPATIENT', $this->tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'accommodation-create-0001',
        );
        $this->assertFalse($createdResult->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($this->bedVersion->after_digest, $created->inpatient_bed_content_digest);
        $resolution = app(FinanceAccommodationTariffProjection::class)->resolveExact(
            $this->bedVersion->public_id, $this->bedVersion->after_digest, '2026-09-02',
        );
        $this->assertSame(10000, $resolution->amountRupiah);

        $appended = $service->appendVersion(
            $this->steward, $created->public_id, $this->tariff->public_id, '2026-09-03',
            1, $created->current_content_digest, 'Versi lanjutan', 'accommodation-append-0001',
        )->record;
        $retired = $service->retire(
            $this->steward, $created->public_id, '2026-09-04', 2,
            $appended->current_content_digest, 'Pemetaan dihentikan', 'accommodation-retire-0001',
        )->record;
        $this->assertSame(FinanceAccommodationTariffBinding::RETIRED, $retired->state);
        $this->assertSame(3, FinanceAccommodationTariffBindingVersion::query()->count());
        $this->assertSame(3, FinanceAccommodationTariffOperationReceipt::query()->count());
        $this->assertSame(['ACTIVE', 'ACTIVE', 'RETIRED'], array_column(
            app(FinanceAccommodationTariffProjection::class)->history($this->steward, $created->public_id)['versions'], 'state',
        ));
        try {
            $service->appendVersion(
                $this->steward, $created->public_id, $this->tariff->public_id, '2026-09-05', 3,
                $retired->current_content_digest, 'Tidak boleh dibuka', 'accommodation-reopen-0001',
            );
            $this->fail('Retirement must be terminal.');
        } catch (FinanceTariffDenied $denied) {
            $this->assertSame('binding_retired', $denied->reason);
        }
    }

    public function test_missing_or_corrupt_exact_version_refuses_without_source_materialization(): void
    {
        $projection = app(FinanceAccommodationTariffProjection::class);
        try {
            $projection->resolveExact($this->bedVersion->public_id, $this->bedVersion->after_digest, '2026-09-02');
            $this->fail('An unmapped exact version must remain unresolved.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('unresolved_accommodation_source', $denied->reason);
        }
        try {
            $projection->resolveExact($this->bedVersion->public_id, str_repeat('f', 64), '2026-09-02');
            $this->fail('A corrupt digest must fail closed.');
        } catch (FinanceDenied $denied) {
            $this->assertSame('source_integrity_failure', $denied->reason);
        }
        $this->assertDatabaseCount('finance_accommodation_source_events', 0);
    }

    public function test_model_sql_and_append_only_guards_preserve_reset_seam(): void
    {
        $binding = $this->service()->create(
            $this->steward, $this->bedVersion->public_id, 'INPATIENT', $this->tariff->public_id,
            '2026-09-02', 'Pemetaan awal', 'accommodation-guard-0001',
        )->record;
        try {
            $binding->version = 2;
            $binding->save();
            $this->fail('Mutable head model writes must require the governed service.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $guard = new FinanceAccommodationTariffSqlWriteGuard;
        $guard->assertAllowed('SELECT * FROM finance_accommodation_tariff_bindings');
        try {
            $guard->assertAllowed('UPDATE finance_accommodation_tariff_bindings SET state = \'RETIRED\'');
            $this->fail('Direct accommodation SQL writes must be rejected.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        FinanceTariffSchemaMutationScope::run(fn () => FinanceAccommodationTariffAppendOnlyGuard::runSyntheticReset(
            fn () => DB::table('finance_accommodation_tariff_operation_receipts')->delete(),
        ));
        $this->assertDatabaseCount('finance_accommodation_tariff_operation_receipts', 0);
        $this->assertDatabaseCount('finance_accommodation_tariff_binding_versions', 1);
    }

    /** @return array{InpatientBedVersion,FinanceTariffItem} */
    private function upstreams(): array
    {
        $admin = $this->actor(RoleCapabilityMatrix::ROLE_ADMIN);
        $masters = app(InpatientMasterService::class);
        $ward = $masters->createWard($admin, 'AKOMODASI', 'Bangsal Akomodasi', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-ward-0001', null)->master;
        if (! $ward instanceof InpatientWard) {
            throw new LogicException('Expected ward.');
        }
        $bed = $masters->createBed($admin, $ward->public_id, 'AKO-01', 'Bed Akomodasi', 'Ruang A', 'Kelas 1', InpatientMasterService::REASON_INITIAL_SETUP, 'accommodation-bed-0001', null)->master;
        if (! $bed instanceof InpatientBed) {
            throw new LogicException('Expected bed.');
        }
        $bedVersion = InpatientBedVersion::query()->where('bed_id', $bed->id)->where('version', 1)->sole();

        $service = app(FinanceTariffMasterService::class);
        $group = $service->createGroup($this->steward, 'GAKO', 'Akomodasi', 'Penyiapan awal', 'accommodation-group-0001')->record;
        if (! $group instanceof FinanceCostComponentGroup) {
            throw new LogicException('Expected group.');
        }
        $component = $service->createComponent($this->steward, $group->public_id, 'CAKO', 'Komponen akomodasi', null, null, 'Penyiapan awal', 'accommodation-component-0001')->record;
        if (! $component instanceof FinanceCostComponent) {
            throw new LogicException('Expected component.');
        }
        $catalogue = $service->createCatalogue($this->steward, 'KAKO', 'Katalog akomodasi', 'Penyiapan awal', 'accommodation-catalogue-0001')->record;
        if (! $catalogue instanceof FinanceTariffCatalogue) {
            throw new LogicException('Expected catalogue.');
        }
        $tariff = $service->createTariffItem(
            $this->steward, $catalogue->public_id, $component->public_id, 'TAKO', 'Akomodasi rawat inap',
            'INPATIENT', 'ACCOMMODATION', null, null, 10000, '2026-09-02', 'Penyiapan awal', 'accommodation-tariff-0001',
        )->record;
        if (! $tariff instanceof FinanceTariffItem) {
            throw new LogicException('Expected tariff.');
        }

        return [$bedVersion, $tariff];
    }

    private function service(): FinanceAccommodationTariffBindingService
    {
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $action, string $resourceType, ?string $resourceId = null, ?User $actor = null, string $outcome = 'SUCCESS', ?string $reason = null, array $metadata = [], ?Request $request = null, bool $includeRequestFingerprint = true): AuditEvent
            {
                return new AuditEvent;
            }
        });

        return app(FinanceAccommodationTariffBindingService::class);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $role)->sole()->id]);

        return $user->fresh();
    }
}
