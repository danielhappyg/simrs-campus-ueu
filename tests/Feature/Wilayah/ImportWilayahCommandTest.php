<?php

namespace Tests\Feature\Wilayah;

use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ImportWilayahCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourcePath = storage_path('framework/testing/wilayah-import-'.Str::lower((string) Str::ulid()));
        foreach (['kabupaten', 'kecamatan', 'kelurahan'] as $directory) {
            File::ensureDirectoryExists($this->sourcePath.'/'.$directory);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sourcePath);

        parent::tearDown();
    }

    public function test_fresh_import_replaces_a_fully_validated_hierarchy(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_provinces', 1);
        $this->assertDatabaseCount('wilayah_regencies', 1);
        $this->assertDatabaseCount('wilayah_districts', 1);
        $this->assertDatabaseCount('wilayah_villages', 1);
        $this->assertDatabaseMissing('wilayah_provinces', ['code' => '32']);
        $this->assertDatabaseHas('wilayah_villages', [
            'code' => '3173011001',
            'district_code' => '317301',
            'name' => 'Kedoya Utara Baru',
        ]);
    }

    public function test_malformed_required_file_fails_before_fresh_import_can_delete_existing_rows(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();
        File::put($this->sourcePath.'/kecamatan/3173.json', '{malformed');

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_provinces', 4);
        $this->assertDatabaseCount('wilayah_regencies', 6);
        $this->assertDatabaseCount('wilayah_districts', 7);
        $this->assertDatabaseCount('wilayah_villages', 8);
        $this->assertDatabaseHas('wilayah_provinces', ['code' => '32', 'name' => 'Jawa Barat']);
    }

    public function test_missing_hierarchy_file_and_invalid_child_code_fail_closed(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();
        File::delete($this->sourcePath.'/kelurahan/317301.json');

        $missingExitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $missingExitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_provinces', 4);

        $this->writeValidHierarchy();
        File::put(
            $this->sourcePath.'/kecamatan/3173.json',
            json_encode([['id' => '327501', 'nama' => 'Wrong parent']], JSON_THROW_ON_ERROR),
        );

        $invalidExitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $invalidExitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_provinces', 4);
        $this->assertDatabaseHas('wilayah_provinces', ['code' => '31', 'name' => 'DKI Jakarta']);
    }

    public function test_prefix_matching_but_structurally_short_hierarchy_code_is_rejected(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();
        File::put(
            $this->sourcePath.'/kecamatan/3173.json',
            json_encode([['id' => '31730', 'nama' => 'Short district']], JSON_THROW_ON_ERROR),
        );
        File::put(
            $this->sourcePath.'/kelurahan/31730.json',
            json_encode([['id' => '3173010001', 'nama' => 'Plausible child']], JSON_THROW_ON_ERROR),
        );

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_provinces', 4);
        $this->assertDatabaseHas('wilayah_provinces', ['code' => '31', 'name' => 'DKI Jakarta']);
    }

    public function test_duplicate_code_is_rejected_before_fresh_import_can_delete_existing_rows(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();
        File::put(
            $this->sourcePath.'/kelurahan/317301.json',
            json_encode([
                ['id' => '3173011001', 'nama' => 'First duplicate'],
                ['id' => '3173011001', 'nama' => 'Second duplicate'],
            ], JSON_THROW_ON_ERROR),
        );

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_provinces', 4);
        $this->assertDatabaseCount('wilayah_villages', 8);
        $this->assertDatabaseHas('wilayah_villages', ['code' => '3173011001', 'name' => 'Kedoya Utara']);
    }

    public function test_database_failure_after_fresh_deletes_rolls_the_previous_hierarchy_back(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();
        $failureInjected = false;
        DB::listen(function (QueryExecuted $query) use (&$failureInjected): void {
            if (! $failureInjected
                && str_contains(strtolower($query->sql), 'wilayah_districts')
                && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                $failureInjected = true;

                throw new \RuntimeException('Injected district write failure.');
            }
        });

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertTrue($failureInjected);
        $this->assertDatabaseCount('wilayah_provinces', 4);
        $this->assertDatabaseCount('wilayah_regencies', 6);
        $this->assertDatabaseCount('wilayah_districts', 7);
        $this->assertDatabaseCount('wilayah_villages', 8);
        $this->assertDatabaseHas('wilayah_provinces', ['code' => '32', 'name' => 'Jawa Barat']);
    }

    public function test_source_drift_between_validation_and_import_rolls_fresh_deletes_back(): void
    {
        $this->seed(WilayahMinimalSeeder::class);
        $this->writeValidHierarchy();
        $sourceChanged = false;
        DB::listen(function (QueryExecuted $query) use (&$sourceChanged): void {
            if ($sourceChanged
                || ! str_contains(strtolower($query->sql), 'wilayah_villages')
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
                return;
            }

            $sourceChanged = true;
            File::put(
                $this->sourcePath.'/kecamatan/3173.json',
                json_encode([['id' => '317301', 'nama' => 'Changed after validation']], JSON_THROW_ON_ERROR),
            );
        });

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertTrue($sourceChanged);
        $this->assertDatabaseCount('wilayah_provinces', 4);
        $this->assertDatabaseCount('wilayah_regencies', 6);
        $this->assertDatabaseCount('wilayah_districts', 7);
        $this->assertDatabaseCount('wilayah_villages', 8);
        $this->assertDatabaseHas('wilayah_districts', ['code' => '317301', 'name' => 'Kebon Jeruk']);
    }

    public function test_upsert_buffers_span_village_source_file_boundaries(): void
    {
        $this->writeBufferedVillageHierarchy();
        $villageUpsertQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$villageUpsertQueries): void {
            $sql = str_replace(['"', '`'], '', strtolower(ltrim($query->sql)));
            if (preg_match('/\Ainsert\s+into\s+(?:[a-z0-9_]+\.)?wilayah_villages\b/', $sql) === 1) {
                $villageUpsertQueries++;
            }
        });

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => $this->sourcePath,
            '--fresh' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertDatabaseCount('wilayah_villages', 603);
        $this->assertSame(
            2,
            $villageUpsertQueries,
            'Three source files totalling 603 villages should flush one 500-row chunk and one remainder.',
        );
    }

    public function test_bundled_ibnux_source_passes_complete_hierarchy_validation_in_fresh_process(): void
    {
        $sourcePath = resource_path('data/wilayah/ibnux');
        if (! File::isDirectory($sourcePath)) {
            $this->markTestSkipped(
                'The optional full ibnux source is not tracked; fetch it before running this dataset validation.',
            );
        }
        $this->assertTrue(
            File::isReadable($sourcePath.'/provinsi.json'),
            'An installed full ibnux source must include a readable provinsi.json root.',
        );

        $databasePath = tempnam(storage_path('framework/testing'), 'wilayah-bundled-');
        $this->assertNotFalse($databasePath);

        $environment = [
            'APP_ENV' => 'testing',
            'APP_MODE' => 'SIMULATION',
            'APP_SYNTHETIC_ONLY' => 'true',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath,
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];

        try {
            $migration = new Process([
                PHP_BINARY,
                '-d',
                'memory_limit=128M',
                base_path('artisan'),
                'migrate:fresh',
                '--force',
                '--no-interaction',
            ], base_path(), $environment, null, 120);
            $migration->run();
            $this->assertSame(0, $migration->getExitCode(), $this->processOutput($migration));

            $import = new Process([
                PHP_BINARY,
                '-d',
                'memory_limit=128M',
                base_path('artisan'),
                'wilayah:import',
                '--path',
                $sourcePath,
                '--fresh',
                '--no-interaction',
            ], base_path(), $environment, null, 120);
            $import->run();
            $this->assertSame(0, $import->getExitCode(), $this->processOutput($import));

            $connection = new PDO('sqlite:'.$databasePath);
            $expectedRowCounts = [
                'wilayah_provinces' => 38,
                'wilayah_regencies' => 514,
                'wilayah_districts' => 7285,
                'wilayah_villages' => 83_762,
            ];

            foreach ($expectedRowCounts as $table => $expectedRowCount) {
                $statement = $connection->query("select count(*) from {$table}");
                if ($statement === false) {
                    $this->fail("Could not query {$table} row count.");
                }

                $actualRowCount = $statement->fetchColumn();
                $this->assertSame($expectedRowCount, (int) $actualRowCount, "Unexpected {$table} row count.");
            }
        } finally {
            unset($connection);
            File::delete([
                $databasePath,
                $databasePath.'-journal',
                $databasePath.'-shm',
                $databasePath.'-wal',
            ]);
        }
    }

    private function processOutput(Process $process): string
    {
        return trim($process->getOutput().PHP_EOL.$process->getErrorOutput());
    }

    private function writeBufferedVillageHierarchy(): void
    {
        File::put(
            $this->sourcePath.'/provinsi.json',
            json_encode([['id' => '31', 'nama' => 'DKI Jakarta']], JSON_THROW_ON_ERROR),
        );
        File::put(
            $this->sourcePath.'/kabupaten/31.json',
            json_encode([['id' => '3173', 'nama' => 'Kota Jakarta Barat']], JSON_THROW_ON_ERROR),
        );

        $districts = [];
        for ($district = 1; $district <= 3; $district++) {
            $districtCode = '3173'.str_pad((string) $district, 2, '0', STR_PAD_LEFT);
            $districts[] = ['id' => $districtCode, 'nama' => "District {$district}"];
            $villages = [];
            for ($village = 1; $village <= 201; $village++) {
                $villages[] = [
                    'id' => $districtCode.str_pad((string) $village, 4, '0', STR_PAD_LEFT),
                    'nama' => "Village {$district}-{$village}",
                ];
            }

            File::put(
                $this->sourcePath."/kelurahan/{$districtCode}.json",
                json_encode($villages, JSON_THROW_ON_ERROR),
            );
        }

        File::put(
            $this->sourcePath.'/kecamatan/3173.json',
            json_encode($districts, JSON_THROW_ON_ERROR),
        );
    }

    private function writeValidHierarchy(): void
    {
        $documents = [
            'provinsi.json' => [['id' => '31', 'nama' => 'DKI Jakarta Baru']],
            'kabupaten/31.json' => [['id' => '3173', 'nama' => 'Kota Jakarta Barat Baru']],
            'kecamatan/3173.json' => [['id' => '317301', 'nama' => 'Kebon Jeruk Baru']],
            'kelurahan/317301.json' => [['id' => '3173011001', 'nama' => 'Kedoya Utara Baru']],
        ];

        foreach ($documents as $relativePath => $document) {
            File::put(
                $this->sourcePath.'/'.$relativePath,
                json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        }
    }
}
