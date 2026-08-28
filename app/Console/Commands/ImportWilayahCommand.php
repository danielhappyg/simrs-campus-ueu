<?php

namespace App\Console\Commands;

use App\Models\WilayahDistrict;
use App\Models\WilayahProvince;
use App\Models\WilayahRegency;
use App\Models\WilayahVillage;
use App\Services\Wilayah\WilayahRepository;
use App\Support\Database\SchemaQualifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;
use LogicException;
use RuntimeException;
use Throwable;

class ImportWilayahCommand extends Command
{
    private const MAX_DATASET_ROWS = 200_000;

    private const MAX_SOURCE_FILE_BYTES = 1024 * 1024;

    private const MAX_SOURCE_FILE_ROWS = 20_000;

    private const UPSERT_CHUNK_SIZE = 500;

    protected $signature = 'wilayah:import
        {--path= : Override path to ibnux JSON root (contains provinsi.json)}
        {--fresh : Truncate wilayah tables before import}';

    protected $description = 'Import ibnux/data-indonesia JSON into wilayah_* tables';

    public function handle(WilayahRepository $repository): int
    {
        $path = rtrim((string) ($this->option('path') ?: $repository->dataPath()), '/\\');

        try {
            $snapshot = $this->validatedSourceSnapshot($path);

            DB::transaction(function () use ($path, $snapshot): void {
                if ($this->option('fresh')) {
                    $this->warn('Replacing wilayah tables atomically…');
                    WilayahVillage::query()->delete();
                    WilayahDistrict::query()->delete();
                    WilayahRegency::query()->delete();
                    WilayahProvince::query()->delete();
                }

                $remainingSnapshot = $snapshot;
                $this->importVerifiedSnapshot($path, $remainingSnapshot);

                if ($remainingSnapshot !== []) {
                    throw new RuntimeException('The wilayah source hierarchy changed after validation.');
                }
            }, 1);
        } catch (Throwable $exception) {
            $this->error('Wilayah import refused: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('DB counts: provinces='.WilayahProvince::query()->count()
            .' regencies='.WilayahRegency::query()->count()
            .' districts='.WilayahDistrict::query()->count()
            .' villages='.WilayahVillage::query()->count());

        return self::SUCCESS;
    }

    /** @return array<string, string> Relative source path to SHA-256 hash. */
    private function validatedSourceSnapshot(string $path): array
    {
        if ($path === '') {
            throw new RuntimeException('The source path is empty.');
        }

        $snapshot = [];
        $totalRows = 0;
        $seen = [
            'province' => [],
            'regency' => [],
            'district' => [],
            'village' => [],
        ];

        $provinces = $this->readAndSnapshot($path, 'provinsi.json', 'province', $snapshot);
        foreach ($provinces as $province) {
            [$provinceCode, $provinceName] = $this->validatedUniqueRow(
                $province,
                'province',
                null,
                $seen['province'],
                $totalRows,
            );

            $regencies = $this->readAndSnapshot(
                $path,
                'kabupaten/'.$provinceCode.'.json',
                'regency',
                $snapshot,
            );
            foreach ($regencies as $regency) {
                [$regencyCode] = $this->validatedUniqueRow(
                    $regency,
                    'regency',
                    $provinceCode,
                    $seen['regency'],
                    $totalRows,
                );

                $districts = $this->readAndSnapshot(
                    $path,
                    'kecamatan/'.$regencyCode.'.json',
                    'district',
                    $snapshot,
                );
                foreach ($districts as $district) {
                    [$districtCode] = $this->validatedUniqueRow(
                        $district,
                        'district',
                        $regencyCode,
                        $seen['district'],
                        $totalRows,
                    );

                    $villages = $this->readAndSnapshot(
                        $path,
                        'kelurahan/'.$districtCode.'.json',
                        'village',
                        $snapshot,
                    );
                    foreach ($villages as $village) {
                        $this->validatedUniqueRow(
                            $village,
                            'village',
                            $districtCode,
                            $seen['village'],
                            $totalRows,
                        );
                    }
                    unset($villages);
                }
                unset($districts);
            }
            unset($regencies);

            $this->line("  validated province {$provinceCode} {$provinceName}");
        }
        unset($provinces, $seen);

        $this->info('Validated source snapshot: '.count($snapshot).' files, '.$totalRows.' rows.');

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $remainingSnapshot
     */
    private function importVerifiedSnapshot(string $path, array &$remainingSnapshot): void
    {
        $now = now()->toDateTimeString();

        $provinceBuffer = [];
        $provinceRowCount = 0;
        $provinces = $this->readVerifiedSnapshot(
            $path,
            'provinsi.json',
            'province',
            $remainingSnapshot,
        );
        $provinceCodes = $this->bufferSourceRows(
            'wilayah_provinces',
            $provinces,
            'province',
            null,
            null,
            $now,
            ['name', 'updated_at'],
            $provinceBuffer,
            $provinceRowCount,
            true,
        );
        unset($provinces);
        $this->flushUpsertBuffer('wilayah_provinces', $provinceBuffer, ['name', 'updated_at']);
        $this->line("Upserted wilayah_provinces: {$provinceRowCount} rows");

        $regencyBuffer = [];
        $regencyCodes = [];
        $regencyRowCount = 0;
        foreach ($provinceCodes as $provinceCode) {
            $regencies = $this->readVerifiedSnapshot(
                $path,
                'kabupaten/'.$provinceCode.'.json',
                'regency',
                $remainingSnapshot,
            );
            $codes = $this->bufferSourceRows(
                'wilayah_regencies',
                $regencies,
                'regency',
                $provinceCode,
                'province_code',
                $now,
                ['province_code', 'name', 'updated_at'],
                $regencyBuffer,
                $regencyRowCount,
                true,
            );
            foreach ($codes as $code) {
                $regencyCodes[] = $code;
            }
            unset($codes, $regencies);
        }
        unset($provinceCodes);
        $this->flushUpsertBuffer(
            'wilayah_regencies',
            $regencyBuffer,
            ['province_code', 'name', 'updated_at'],
        );
        $this->line("Upserted wilayah_regencies: {$regencyRowCount} rows");

        $districtBuffer = [];
        $districtCodes = [];
        $districtRowCount = 0;
        foreach ($regencyCodes as $regencyCode) {
            $districts = $this->readVerifiedSnapshot(
                $path,
                'kecamatan/'.$regencyCode.'.json',
                'district',
                $remainingSnapshot,
            );
            $codes = $this->bufferSourceRows(
                'wilayah_districts',
                $districts,
                'district',
                $regencyCode,
                'regency_code',
                $now,
                ['regency_code', 'name', 'updated_at'],
                $districtBuffer,
                $districtRowCount,
                true,
            );
            foreach ($codes as $code) {
                $districtCodes[] = $code;
            }
            unset($codes, $districts);
        }
        unset($regencyCodes);
        $this->flushUpsertBuffer(
            'wilayah_districts',
            $districtBuffer,
            ['regency_code', 'name', 'updated_at'],
        );
        $this->line("Upserted wilayah_districts: {$districtRowCount} rows");

        $villageBuffer = [];
        $villageRowCount = 0;
        foreach ($districtCodes as $districtCode) {
            $villages = $this->readVerifiedSnapshot(
                $path,
                'kelurahan/'.$districtCode.'.json',
                'village',
                $remainingSnapshot,
            );
            $this->bufferSourceRows(
                'wilayah_villages',
                $villages,
                'village',
                $districtCode,
                'district_code',
                $now,
                ['district_code', 'name', 'updated_at'],
                $villageBuffer,
                $villageRowCount,
                false,
            );
            unset($villages);
        }
        unset($districtCodes);
        $this->flushUpsertBuffer(
            'wilayah_villages',
            $villageBuffer,
            ['district_code', 'name', 'updated_at'],
        );
        $this->line("Upserted wilayah_villages: {$villageRowCount} rows");
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @param  list<string>  $update
     * @param  list<array<string, mixed>>  $buffer
     * @return list<string>
     */
    private function bufferSourceRows(
        string $table,
        array $sourceRows,
        string $level,
        ?string $parentCode,
        ?string $parentColumn,
        string $now,
        array $update,
        array &$buffer,
        int &$rowCount,
        bool $collectCodes,
    ): array {
        if (($parentCode === null) !== ($parentColumn === null)) {
            throw new LogicException('Wilayah parent code and column must be provided together.');
        }

        $codes = [];

        foreach ($sourceRows as $sourceRow) {
            [$code, $name] = $this->validatedScalarRow($sourceRow, $level, $parentCode);
            $row = $this->databaseRow($code, $name, $now);
            if ($parentColumn !== null && $parentCode !== null) {
                $row[$parentColumn] = $parentCode;
            }
            $buffer[] = $row;
            $rowCount++;
            if ($collectCodes) {
                $codes[] = $code;
            }

            if (count($buffer) === self::UPSERT_CHUNK_SIZE) {
                $this->flushUpsertBuffer($table, $buffer, $update);
            }
        }

        return $codes;
    }

    /**
     * @param  list<array<string, mixed>>  $buffer
     * @param  list<string>  $update
     */
    private function flushUpsertBuffer(string $table, array &$buffer, array $update): void
    {
        if ($buffer === []) {
            return;
        }

        DB::table(SchemaQualifier::table($table))->upsert($buffer, ['code'], $update);
        $buffer = [];
    }

    /**
     * @param  array<string, string>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function readAndSnapshot(string $path, string $relativePath, string $level, array &$snapshot): array
    {
        if (array_key_exists($relativePath, $snapshot)) {
            throw new RuntimeException('The wilayah hierarchy references a required file more than once.');
        }

        $source = $this->readSourceFile($path, $relativePath, $level);
        $snapshot[$relativePath] = $source['hash'];

        return $source['rows'];
    }

    /**
     * @param  array<string, string>  $remainingSnapshot
     * @return list<array<string, mixed>>
     */
    private function readVerifiedSnapshot(
        string $path,
        string $relativePath,
        string $level,
        array &$remainingSnapshot,
    ): array {
        $expectedHash = $remainingSnapshot[$relativePath] ?? null;
        if (! is_string($expectedHash)) {
            throw new RuntimeException('The wilayah source hierarchy changed after validation.');
        }

        $source = $this->readSourceFile($path, $relativePath, $level);
        if (! hash_equals($expectedHash, $source['hash'])) {
            throw new RuntimeException('A required wilayah source file changed after validation.');
        }

        unset($remainingSnapshot[$relativePath]);

        return $source['rows'];
    }

    /** @return array{hash: string, rows: list<array<string, mixed>>} */
    private function readSourceFile(string $path, string $relativePath, string $level): array
    {
        $file = $path.'/'.$relativePath;
        if (! File::isFile($file) || ! File::isReadable($file)) {
            throw new RuntimeException("Required {$level} source file is missing or unreadable.");
        }

        $raw = file_get_contents($file, false, null, 0, self::MAX_SOURCE_FILE_BYTES + 1);
        if (! is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::MAX_SOURCE_FILE_BYTES) {
            throw new RuntimeException("The {$level} source file has an unsafe size.");
        }

        $hash = hash('sha256', $raw);
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$level} source file is malformed JSON.", previous: $exception);
        } finally {
            unset($raw);
        }

        if (! is_array($decoded)
            || ! array_is_list($decoded)
            || $decoded === []
            || count($decoded) > self::MAX_SOURCE_FILE_ROWS) {
            throw new RuntimeException("The {$level} source must be a non-empty bounded list.");
        }

        return ['hash' => $hash, 'rows' => $decoded];
    }

    /**
     * @param  array<string, true>  $seen
     * @return array{string, string}
     */
    private function validatedUniqueRow(
        mixed $row,
        string $level,
        ?string $parentCode,
        array &$seen,
        int &$totalRows,
    ): array {
        [$code, $name] = $this->validatedScalarRow($row, $level, $parentCode);

        if (isset($seen[$code])) {
            throw new RuntimeException("The {$level} source contains a duplicate code.");
        }
        $seen[$code] = true;
        $totalRows++;
        if ($totalRows > self::MAX_DATASET_ROWS) {
            throw new RuntimeException('The wilayah source exceeds the maximum total row count.');
        }

        return [$code, $name];
    }

    /** @return array{string, string} */
    private function validatedScalarRow(mixed $row, string $level, ?string $parentCode): array
    {
        if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['nama'] ?? null)) {
            throw new RuntimeException("A {$level} row is missing its string id or nama field.");
        }

        $code = trim($row['id']);
        $name = trim($row['nama']);
        $expectedCodeLength = match ($level) {
            'province' => 2,
            'regency' => 4,
            'district' => 6,
            'village' => 10,
            default => throw new RuntimeException('Unsupported wilayah hierarchy level.'),
        };
        if (strlen($code) !== $expectedCodeLength || preg_match('/\A[0-9]+\z/', $code) !== 1
            || ($parentCode !== null && ! str_starts_with($code, $parentCode))) {
            throw new RuntimeException("A {$level} row has an invalid hierarchy code.");
        }
        if (! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) < 1 || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new RuntimeException("A {$level} row has an invalid name.");
        }

        return [$code, $name];
    }

    /** @return array<string, mixed> */
    private function databaseRow(string $code, string $name, string $now): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
