<?php

namespace App\Support\Inpatient;

use LogicException;

final class InpatientMasterSqlWriteGuard
{
    /** @var list<string> */
    private const PROTECTED_TABLES = [
        'inpatient_wards',
        'inpatient_ward_versions',
        'inpatient_beds',
        'inpatient_bed_versions',
        'inpatient_master_operation_receipts',
        'inpatient_master_code_reservations',
    ];

    public function assertAllowed(string $sql): void
    {
        if (InpatientMasterMutationScope::isActive() || InpatientMasterDirectWriteScope::isActive()) {
            return;
        }

        $withoutComments = preg_replace('/\/\*.*?\*\/|--[^\r\n]*/s', ' ', $sql) ?? $sql;
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $withoutComments) ?? $withoutComments;
        $normalized = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $withoutLiterals));
        if (preg_match('/^\s*(?:select|show|pragma|create|alter|drop)\b/is', $normalized) === 1) {
            return;
        }
        if (preg_match('/^\s*explain\b/is', $normalized) === 1
            && preg_match('/\banalyze\b/is', $normalized) !== 1) {
            return;
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge)\b/is', $normalized) !== 1) {
            return;
        }

        foreach (self::PROTECTED_TABLES as $table) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1) {
                throw new LogicException('Direct SQL writes to inpatient master tables are prohibited.');
            }
        }
    }
}
