<?php

namespace Tests\Feature\Wilayah;

use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

    public function test_bundled_ibnux_source_passes_complete_hierarchy_validation(): void
    {
        $upsertQueries = [
            'wilayah_provinces' => 0,
            'wilayah_regencies' => 0,
            'wilayah_districts' => 0,
            'wilayah_villages' => 0,
        ];
        DB::listen(function (QueryExecuted $query) use (&$upsertQueries): void {
            $sql = strtolower(ltrim($query->sql));
            if (! str_starts_with($sql, 'insert')) {
                return;
            }

            foreach (array_keys($upsertQueries) as $table) {
                if (str_contains($sql, '"'.$table.'"')) {
                    $upsertQueries[$table]++;

                    return;
                }
            }
        });

        $exitCode = Artisan::call('wilayah:import', [
            '--path' => resource_path('data/wilayah/ibnux'),
            '--fresh' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $rowCounts = [
            'wilayah_provinces' => DB::table('wilayah_provinces')->count(),
            'wilayah_regencies' => DB::table('wilayah_regencies')->count(),
            'wilayah_districts' => DB::table('wilayah_districts')->count(),
            'wilayah_villages' => DB::table('wilayah_villages')->count(),
        ];
        $this->assertGreaterThan(30, $rowCounts['wilayah_provinces']);
        $this->assertGreaterThan(400, $rowCounts['wilayah_regencies']);
        $this->assertGreaterThan(6000, $rowCounts['wilayah_districts']);
        $this->assertGreaterThan(70_000, $rowCounts['wilayah_villages']);

        foreach ($rowCounts as $table => $rowCount) {
            $this->assertSame(
                (int) ceil($rowCount / 500),
                $upsertQueries[$table],
                "{$table} should use only full cross-file chunks plus one remainder upsert.",
            );
        }
        $this->assertLessThan(200, array_sum($upsertQueries));
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
