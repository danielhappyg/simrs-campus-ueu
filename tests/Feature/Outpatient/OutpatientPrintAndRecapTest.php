<?php

namespace Tests\Feature\Outpatient;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\RoleCapabilityMatrix;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\CompositeExpectation;
use Tests\TestCase;

class OutpatientPrintAndRecapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_print_or_open_rekap(): void
    {
        $encounter = Encounter::factory()->create();

        $this->get(route('pendaftaran.kunjungan.cetak', $encounter))
            ->assertRedirect();

        $this->get(route('pendaftaran.rekap'))
            ->assertRedirect();
    }

    public function test_registrar_can_print_teaching_bukti_and_sep(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'sex' => Patient::SEX_PEREMPUAN,
            'religion' => 'ISLAM',
            'marital_status' => Patient::MARITAL_KAWIN,
            'ethnicity' => 'JAWA',
            'province' => 'DKI JAKARTA',
            'province_code' => '31',
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'payer_type' => Encounter::PAYER_BPJS,
            'insurance_number' => 'SYNTH-0001',
            'queue_number' => 7,
            'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
        ]);

        $response = $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs=bukti,sep,antrian');

        $response->assertOk();
        $response->assertSee('Dokumen pengajaran', false);
        $response->assertSee('Bukti pendaftaran', false);
        $response->assertSee('SEP pengajaran', false);
        $response->assertSee('Tidak dikirim ke VClaim', false);
        $response->assertSee('SIM-SEP-', false);
        $response->assertSee('Perempuan', false);
        $response->assertSee('Islam', false);
        $response->assertSee('Kawin', false);
        $response->assertSee('Datang sendiri', false);
        $response->assertSee('DKI JAKARTA', false);
        $response->assertSee('31', false);
        $response->assertDontSee('LAKI_LAKI', false);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.print',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
    }

    public function test_print_hides_non_synthetic_patients(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'is_synthetic' => false,
            'created_by_user_id' => $registrar->id,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter))
            ->assertNotFound();
    }

    public function test_print_deduplicates_repeated_document_keys_before_auditing_and_rendering(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->printableEncounter($registrar);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs='.implode(',', array_fill(0, 7, 'bukti')))
            ->assertOk();

        $event = AuditEvent::query()->where('action', 'encounter.print')->sole();
        $this->assertSame(['bukti'], $event->metadata['documents']);
    }

    public function test_print_returns_service_unavailable_when_audit_cannot_be_recorded(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $encounter = $this->printableEncounter($registrar);
        $recorder = Mockery::mock(AuditRecorder::class);
        $expectation = $recorder->shouldReceive('record');
        $this->assertInstanceOf(CompositeExpectation::class, $expectation);
        $expectation->andReturn(null);
        $this->app->instance(AuditRecorder::class, $recorder);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter))
            ->assertStatus(503);

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_rekap_filters_online_booking_and_exports_csv(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        Encounter::factory()->create([
            'registered_by_user_id' => $registrar->id,
            'clinic_name' => 'Poli Dalam',
            'booking_code' => 'RGN-ONLINE-1',
            'registered_at' => now(),
        ]);
        Encounter::factory()->create([
            'registered_by_user_id' => $registrar->id,
            'clinic_name' => 'Poli Dalam',
            'booking_code' => null,
            'registered_at' => now(),
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'origin' => 'ONLINE',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pendaftaran/rekap')
                ->where('totals.all', 1)
                ->where('totals.online', 1)
                ->where('rows.0.booking_code', 'RGN-ONLINE-1'));

        $csv = $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'origin' => 'WALK_IN',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]));

        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString('Walk-in', $csvContent);
        $this->assertStringNotContainsString('RGN-ONLINE-1', $csvContent);
    }

    public function test_recap_totals_pages_and_csv_cover_the_complete_filtered_result(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
            'medical_record_number' => 'SYNTH-RECAP-501',
            'full_name' => 'Pasien Rekap Sintetis',
        ]);

        Encounter::factory()->count(501)->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'registered_at' => now(),
        ]);

        $filters = [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ];

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 500)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 501)
                ->where('pagination.from', 1)
                ->where('pagination.to', 500)
                ->where('totals.all', 501)
                ->where('totals.online', 0)
                ->where('totals.walk_in', 501));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [...$filters, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('pagination.current_page', 2)
                ->where('pagination.from', 501)
                ->where('pagination.to', 501)
                ->where('totals.all', 501));

        $csv = $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [...$filters, 'format' => 'csv', 'page' => 2]));
        $csv->assertOk();
        $lines = array_values(array_filter(explode("\n", trim($csv->streamedContent()))));
        $this->assertCount(502, $lines, 'CSV must contain its header and all 501 filtered rows, independent of screen page.');
    }

    public function test_recap_csv_neutralizes_spreadsheet_formula_prefixes(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
            'medical_record_number' => '+SYNTH-FORMULA',
            'full_name' => '=HYPERLINK("https://example.invalid")',
        ]);
        Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'registered_at' => now(),
        ]);
        $whitespacePatient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
            'medical_record_number' => ' @SUM(1,1)',
            'full_name' => "\n=SUM(1,1)",
        ]);
        Encounter::factory()->create([
            'patient_id' => $whitespacePatient->id,
            'registered_by_user_id' => $registrar->id,
            'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            'registered_at' => now(),
        ]);

        $csv = $this->actingAs($registrar)->get(route('pendaftaran.rekap', [
            'format' => 'csv',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));
        $content = $csv->streamedContent();

        $this->assertStringContainsString("'+SYNTH-FORMULA", $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString("' @SUM(1,1)", $content);
        $this->assertStringContainsString("'\n=SUM(1,1)", $content);
        $this->assertStringNotContainsString(',+SYNTH-FORMULA', $content);
        $this->assertStringNotContainsString(',=HYPERLINK', $content);
    }

    public function test_recap_csv_refuses_missing_dates(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap').'?format=csv&date_from=&date_to=&care_setting=ALL')
            ->assertRedirect(route('pendaftaran.rekap', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'care_setting' => 'ALL',
            ]))
            ->assertSessionHas('error', 'Tanggal awal dan akhir yang valid wajib dipilih untuk ekspor CSV.');

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'date_from' => 'invalid',
                'date_to' => now()->toDateString(),
            ]))
            ->assertRedirect(route('pendaftaran.rekap', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            ]))
            ->assertSessionHas('error', 'Tanggal awal dan akhir yang valid wajib dipilih untuk ekspor CSV.');
    }

    public function test_recap_screen_recovers_invalid_dates_before_querying(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'date_from' => 'not-a-date',
                'date_to' => now()->toDateString(),
            ]))
            ->assertRedirect(route('pendaftaran.rekap', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            ]))
            ->assertSessionHas('error', 'Tanggal filter tidak valid. Rentang dikembalikan ke hari ini.');
    }

    public function test_recap_csv_refuses_overlong_date_range(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'date_from' => now()->subDays(31)->toDateString(),
                'date_to' => now()->toDateString(),
                'care_setting' => 'ALL',
            ]))
            ->assertRedirect(route('pendaftaran.rekap', [
                'date_from' => now()->subDays(31)->toDateString(),
                'date_to' => now()->toDateString(),
                'care_setting' => 'ALL',
            ]))
            ->assertSessionHas('error', 'Ekspor CSV sinkron sementara dibatasi maksimal 31 hari. Persempit rentang tanggal.');
    }

    public function test_recap_csv_allows_the_provisional_thirty_one_day_boundary(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $response = $this->actingAs($registrar)->get(route('pendaftaran.rekap', [
            'format' => 'csv',
            'date_from' => now()->subDays(30)->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Waktu,Antrian', $response->streamedContent());
    }

    public function test_recap_csv_refuses_a_result_above_the_synchronous_row_ceiling(): void
    {
        config(['simulation.recap_csv_max_rows' => 2]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
        ]);
        Encounter::factory()->count(3)->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'registered_at' => now(),
        ]);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertRedirect(route('pendaftaran.rekap', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
            ]))
            ->assertSessionHas('error', 'Ekspor CSV sinkron dibatasi maksimal 2 baris. Persempit filter sebelum mencoba kembali.');
    }

    public function test_recap_csv_limits_repeated_requests_per_user(): void
    {
        config([
            'simulation.recap_csv_user_attempts_per_minute' => 1,
            'simulation.recap_csv_global_attempts_per_minute' => 100,
        ]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $parameters = [
            'format' => 'csv',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ];

        $first = $this->actingAs($registrar)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get(route('pendaftaran.rekap', $parameters));
        $first->assertOk();
        $first->streamedContent();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get(route('pendaftaran.rekap', $parameters))
            ->assertRedirect()
            ->assertSessionHas('error', 'Batas permintaan ekspor CSV tercapai. Tunggu satu menit sebelum mencoba kembali.');
    }

    public function test_recap_csv_applies_a_global_request_budget_across_users(): void
    {
        config([
            'simulation.recap_csv_user_attempts_per_minute' => 100,
            'simulation.recap_csv_global_attempts_per_minute' => 1,
        ]);
        $firstRegistrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $secondRegistrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $parameters = [
            'format' => 'csv',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ];

        $first = $this->actingAs($firstRegistrar)->get(route('pendaftaran.rekap', $parameters));
        $first->assertOk();
        $first->streamedContent();

        $this->actingAs($secondRegistrar)
            ->get(route('pendaftaran.rekap', $parameters))
            ->assertRedirect()
            ->assertSessionHas('error', 'Batas permintaan ekspor CSV tercapai. Tunggu satu menit sebelum mencoba kembali.');
    }

    public function test_recap_csv_refuses_a_second_concurrent_export_for_the_same_user(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $userKey = hash('sha256', $registrar->public_id);
        $lock = Cache::lock('recap-csv:active:'.$userKey, 120);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($registrar)
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
                ->get(route('pendaftaran.rekap', [
                    'format' => 'csv',
                    'date_from' => now()->toDateString(),
                    'date_to' => now()->toDateString(),
                ]))
                ->assertRedirect()
                ->assertSessionHas('error', 'Satu ekspor CSV untuk akun ini masih berjalan. Tunggu hingga selesai.');
        } finally {
            $lock->release();
        }
    }

    public function test_recap_csv_cutoff_excludes_rows_inserted_after_the_bounded_probe_snapshot(): void
    {
        config(['simulation.recap_csv_max_rows' => 5]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $initialPatient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'full_name' => 'Pasien Dalam Snapshot',
            'is_synthetic' => true,
        ]);
        $latePatient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'full_name' => 'Pasien Setelah Snapshot',
            'is_synthetic' => true,
        ]);
        Encounter::factory()->create([
            'patient_id' => $initialPatient->id,
            'registered_by_user_id' => $registrar->id,
            'registered_at' => now(),
        ]);

        $lateRowInserted = false;
        DB::listen(function (QueryExecuted $query) use (&$lateRowInserted, $latePatient, $registrar): void {
            $sql = strtolower($query->sql);
            if ($lateRowInserted
                || ! str_contains($sql, 'from "encounters"')
                || (! str_contains($sql, 'max("id")')
                    && (! str_contains($sql, 'select "id"') || ! str_contains($sql, 'limit')))) {
                return;
            }

            $lateRowInserted = true;
            Encounter::factory()->create([
                'patient_id' => $latePatient->id,
                'registered_by_user_id' => $registrar->id,
                'registered_at' => now(),
            ]);
        });

        $response = $this->actingAs($registrar)->get(route('pendaftaran.rekap', [
            'format' => 'csv',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertTrue($lateRowInserted);
        $this->assertStringContainsString('Pasien Dalam Snapshot', $content);
        $this->assertStringNotContainsString('Pasien Setelah Snapshot', $content);
    }

    public function test_user_without_encounter_list_cannot_open_rekap(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('pendaftaran.rekap'))
            ->assertForbidden();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function printableEncounter(User $registrar): Encounter
    {
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
        ]);

        return Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
        ]);
    }
}
