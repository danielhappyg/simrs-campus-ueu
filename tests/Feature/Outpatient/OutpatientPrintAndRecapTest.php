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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\CompositeExpectation;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function test_registrar_printing_sep_prefers_the_single_visible_sep_document(): void
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
        $response->assertDontSee('Bukti pendaftaran', false);
        $response->assertSee('Surat Eligibilitas Peserta (SEP)', false);
        $response->assertSee('SIM-SEP-', false);
        $response->assertDontSee('Dokumen pengajaran', false);
        $response->assertDontSee('Tidak dikirim ke VClaim', false);
        $response->assertSee($patient->full_name, false);
        $response->assertSee('SYNTH-0001', false);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.print',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);
        $this->assertSame(
            ['sep'],
            AuditEvent::query()->where('action', 'encounter.print')->sole()->metadata['documents'],
        );
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
        $csv->assertHeader('content-disposition', 'attachment; filename="rekap-pendaftaran.csv"');
        $csvContent = $this->csvContent($csv);
        $csv->assertHeader('content-length', (string) strlen($csvContent));
        $this->assertNotInstanceOf(StreamedResponse::class, $csv->baseResponse);
        $this->assertStringContainsString('Walk-in', $csvContent);
        $this->assertStringNotContainsString('RGN-ONLINE-1', $csvContent);
    }

    public function test_recap_retains_cancelled_history_with_a_separate_total_filter_and_csv_status(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $activePatient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'full_name' => 'Pasien Aktif Sintetis',
            'medical_record_number' => 'SYNTH-ACTIVE-RECAP',
        ]);
        $cancelledPatient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'full_name' => 'Pasien Batal Sintetis',
            'medical_record_number' => 'SYNTH-CANCELLED-RECAP',
        ]);
        Encounter::factory()->create([
            'patient_id' => $activePatient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_REGISTERED,
            'registered_at' => now(),
        ]);
        $cancelled = Encounter::factory()->create([
            'patient_id' => $cancelledPatient->id,
            'registered_by_user_id' => $registrar->id,
            'status' => Encounter::STATUS_CANCELLED,
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
                ->where('totals.all', 2)
                ->where('totals.cancelled', 1)
                ->has('rows', 2));

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [...$filters, 'status' => Encounter::STATUS_CANCELLED]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', Encounter::STATUS_CANCELLED)
                ->where('totals.all', 1)
                ->where('totals.cancelled', 1)
                ->has('rows', 1)
                ->where('rows.0.public_id', $cancelled->public_id)
                ->where('rows.0.status', Encounter::STATUS_CANCELLED)
                ->where('rows.0.status_label', 'Dibatalkan'));

        $csv = $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                ...$filters,
                'status' => Encounter::STATUS_CANCELLED,
                'format' => 'csv',
            ]));
        $csv->assertOk();
        $csvContent = $this->csvContent($csv);
        $this->assertStringContainsString('Pasien Batal Sintetis', $csvContent);
        $this->assertStringNotContainsString('Pasien Aktif Sintetis', $csvContent);
        $csvRows = array_map('str_getcsv', array_values(array_filter(explode("\n", trim($csvContent)))));
        $header = $csvRows[0];
        $cancelledRow = $csvRows[1];
        $this->assertSame('Status kunjungan', $header[9]);
        $this->assertSame('Kode status kunjungan', $header[10]);
        $this->assertSame('Dibatalkan', $cancelledRow[9]);
        $this->assertSame(Encounter::STATUS_CANCELLED, $cancelledRow[10]);
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
        $lines = array_values(array_filter(explode("\n", trim($this->csvContent($csv)))));
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
        $content = $this->csvContent($csv);

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
            ->assertSessionHas('error', 'Select valid start and end dates for the CSV export.');

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
            ->assertSessionHas('error', 'Select valid start and end dates for the CSV export.');
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
            ->assertSessionHas('error', 'The filter dates are invalid. The date range has been reset to today.');
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
            ->assertSessionHas('error', 'CSV exports are currently limited to 31 days. Narrow the date range.');
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
        $this->assertNotInstanceOf(StreamedResponse::class, $response->baseResponse);
        $response->assertHeader('content-disposition', 'attachment; filename="rekap-pendaftaran.csv"');
        $response->assertHeader('content-length', (string) strlen($this->csvContent($response)));
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Waktu,Antrian', $this->csvContent($response));
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
            ->assertSessionHas('error', 'CSV exports are limited to 2 rows. Narrow the filters before trying again.');
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
        $this->csvContent($first);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get(route('pendaftaran.rekap', $parameters))
            ->assertRedirect()
            ->assertSessionHas('error', 'The CSV export request limit has been reached. Wait one minute before trying again.');
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
        $this->csvContent($first);

        $this->actingAs($secondRegistrar)
            ->get(route('pendaftaran.rekap', $parameters))
            ->assertRedirect()
            ->assertSessionHas('error', 'The CSV export request limit has been reached. Wait one minute before trying again.');
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
                ->assertSessionHas('error', 'A CSV export for this account is still running. Wait for it to finish.');
        } finally {
            $lock->release();
        }
    }

    public function test_recap_csv_enforces_the_global_active_export_ceiling_across_users(): void
    {
        config([
            'simulation.recap_csv_user_attempts_per_minute' => 100,
            'simulation.recap_csv_global_attempts_per_minute' => 100,
            'simulation.recap_csv_max_active_exports' => 1,
        ]);
        $firstRegistrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $secondRegistrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $firstUserLock = Cache::lock('recap-csv:active:'.hash('sha256', $firstRegistrar->public_id), 120);
        $globalLock = Cache::lock('recap-csv:active:global:1', 120);
        $this->assertTrue($firstUserLock->get());
        $this->assertTrue($globalLock->get());

        $parameters = [
            'format' => 'csv',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ];

        try {
            $this->actingAs($secondRegistrar)
                ->get(route('pendaftaran.rekap', $parameters))
                ->assertRedirect()
                ->assertSessionHas('error', 'The concurrent CSV export limit has been reached. Wait for an export to finish.');
        } finally {
            $globalLock->release();
            $firstUserLock->release();
        }

        $response = $this->actingAs($secondRegistrar)->get(route('pendaftaran.rekap', $parameters));
        $response->assertOk();
        $this->assertStringContainsString('Waktu,Antrian', $this->csvContent($response));
    }

    public function test_recap_csv_releases_owner_safe_user_and_global_slots_before_slow_download_output(): void
    {
        config([
            'simulation.recap_csv_user_attempts_per_minute' => 100,
            'simulation.recap_csv_global_attempts_per_minute' => 100,
            'simulation.recap_csv_max_active_exports' => 1,
        ]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $response = $this->actingAs($registrar)->get(route('pendaftaran.rekap', [
            'format' => 'csv',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));
        $response->assertOk();

        $userLock = Cache::lock('recap-csv:active:'.hash('sha256', $registrar->public_id), 120);
        $globalLock = Cache::lock('recap-csv:active:global:1', 120);
        $this->assertTrue($userLock->get());
        $this->assertTrue($globalLock->get());

        try {
            $this->assertStringContainsString('Waktu,Antrian', $this->csvContent($response));
        } finally {
            $globalLock->release();
            $userLock->release();
        }
    }

    public function test_recap_csv_refuses_output_above_the_byte_ceiling(): void
    {
        config(['simulation.recap_csv_max_bytes' => 10]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertRedirect()
            ->assertSessionHas('error', 'CSV exports are limited to 10 bytes. Narrow the filters before trying again.');
    }

    public function test_recap_csv_enforces_its_execution_ceiling_when_the_configured_lease_is_shorter(): void
    {
        config([
            'simulation.recap_csv_lock_seconds' => 1,
            'simulation.recap_csv_max_execution_seconds' => 1,
        ]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            $sql = $this->normalizedSql($query);
            if ($delayed || ! $this->selectsFrom($sql, 'encounters')) {
                return;
            }

            $delayed = true;
            usleep(1_100_000);
        });

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertRedirect()
            ->assertSessionHas('error', 'The CSV export exceeded the 1-second time limit. Narrow the filters before trying again.');

        $this->assertTrue($delayed);
    }

    public function test_recap_csv_uses_a_transaction_local_postgresql_statement_timeout_when_available(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This assertion requires the configured PostgreSQL connection.');
        }

        config(['simulation.recap_csv_max_execution_seconds' => 7]);
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->actingAs($registrar)
            ->get(route('pendaftaran.rekap', [
                'format' => 'csv',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk();

        $this->assertContains('set local statement_timeout = 6750', $statements);
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
            $sql = $this->normalizedSql($query);
            if ($lateRowInserted
                || ! $this->selectsFrom($sql, 'encounters')
                || (! str_contains($sql, 'max(id)')
                    && (! str_contains($sql, 'select id') || ! str_contains($sql, 'limit')))) {
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
        $content = $this->csvContent($response);
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

    public function test_registrar_can_print_full_general_consent_form(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
            'full_name' => 'Pasien Consent Sintetis',
            'responsible_party_name' => 'Wali Consent Sintetis',
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
            'clinic_name' => 'Poliklinik Umum',
        ]);

        $response = $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs=consent');

        $response->assertOk();
        $response->assertDontSee('Dokumen pengajaran', false);
        $response->assertSee('GENERAL CONSENT', false);
        $response->assertSee('Persetujuan Umum', false);
        $response->assertSee('HAK DAN KEWAJIBAN SEBAGAI PASIEN', false);
        $response->assertSee('PRIVASI', false);
        $response->assertSee('Yang menjelaskan', false);
        $response->assertSee('Pasien / penanggung jawab', false);
        $response->assertDontSee('Pernyataan pengajaran, bukan persetujuan klinis sah.', false);
    }

    public function test_registrar_can_sign_general_consent_and_reprint_marks(): void
    {
        $registrar = $this->userWithRole(RoleCapabilityMatrix::ROLE_REGISTRAR);
        $patient = Patient::factory()->create([
            'created_by_user_id' => $registrar->id,
            'is_synthetic' => true,
        ]);
        $encounter = Encounter::factory()->create([
            'patient_id' => $patient->id,
            'registered_by_user_id' => $registrar->id,
        ]);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $this->actingAs($registrar)
            ->post(route('pendaftaran.kunjungan.consent.store', $encounter), [
                'explainer_name' => 'dr. Penjelas Sintetis',
                'patient_or_guardian_name' => 'Wali Sintetis',
                'explainer_signature_png' => $png,
                'patient_signature_png' => $png,
            ])
            ->assertRedirect(route('pendaftaran.kunjungan.consent.show', $encounter));

        $this->assertDatabaseHas('encounter_consents', [
            'encounter_id' => $encounter->id,
            'explainer_name' => 'dr. Penjelas Sintetis',
            'patient_or_guardian_name' => 'Wali Sintetis',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'encounter.consent.sign',
            'resource_type' => 'encounter',
            'resource_id' => $encounter->public_id,
            'outcome' => 'SUCCESS',
        ]);

        $reprint = $this->actingAs($registrar)
            ->get(route('pendaftaran.kunjungan.cetak', $encounter).'?docs=consent');

        $reprint->assertOk();
        $reprint->assertSee('dr. Penjelas Sintetis', false);
        $reprint->assertSee('Wali Sintetis', false);
        $reprint->assertSee($png, false);
        $reprint->assertSee('GENERAL CONSENT', false);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function normalizedSql(QueryExecuted $query): string
    {
        return str_replace(['"', '`'], '', strtolower($query->sql));
    }

    private function selectsFrom(string $sql, string $table): bool
    {
        return preg_match(
            '/\\bfrom\\s+(?:[a-z0-9_]+\\.)?'.preg_quote($table, '/').'\\b/',
            $sql,
        ) === 1;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function csvContent(TestResponse $response): string
    {
        $content = $response->getContent();
        if (! is_string($content)) {
            $this->fail('CSV response content was not buffered as a string.');
        }

        return $content;
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
