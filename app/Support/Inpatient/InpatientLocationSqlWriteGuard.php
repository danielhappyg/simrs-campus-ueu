<?php

namespace App\Support\Inpatient;

use LogicException;

final class InpatientLocationSqlWriteGuard
{
    /** @var list<string> */
    private const TABLES = ['inpatient_location_events', 'inpatient_location_operation_receipts'];

    public function assertAllowed(string $sql): void
    {
        if (InpatientLocationMutationScope::isActive()) {
            return;
        }
        if (preg_match('/\/\*(?:!|m!)/i', $sql) === 1) {
            throw new LogicException('Executable SQL comments are prohibited on inpatient placement write paths.');
        }
        $withoutComments = preg_replace('/\/\*.*?\*\/|--[^\r\n]*/s', ' ', $sql) ?? $sql;
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $withoutComments) ?? $withoutComments;
        $normalized = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $withoutLiterals));
        // A locking read such as SELECT ... FOR UPDATE contains the word
        // "update", but it does not mutate the append-only location tables.
        // Modifying CTEs start with WITH and continue through the write checks.
        $hasFollowingStatement = preg_match('/;\s*\S/is', $normalized) === 1;
        if (! $hasFollowingStatement
            && preg_match('/^\s*(?:select|show|pragma)\b/is', $normalized) === 1) {
            return;
        }
        if (! $hasFollowingStatement
            && preg_match('/^\s*explain\b/is', $normalized) === 1
            && preg_match('/\banalyze\b/is', $normalized) !== 1) {
            return;
        }
        $isDdl = preg_match('/^\s*(?:create|alter|drop|rename)\b/is', $normalized) === 1;
        $isDml = preg_match('/\b(?:insert|replace|update|delete|truncate|merge)\b/is', $normalized) === 1;
        $ddlTarget = $isDdl ? $this->ddlTarget($normalized) : null;
        if (! $hasFollowingStatement
            && $ddlTarget !== null
            && ! in_array($ddlTarget, self::TABLES, true)) {
            // Cross-domain foreign keys may reference location evidence while
            // the DDL writes only to a different, explicitly parsed table.
            return;
        }
        foreach (self::TABLES as $table) {
            if (($isDdl || $isDml) && str_contains($normalized, $table)) {
                if ($isDdl && InpatientLocationSchemaMutationScope::isActive()) {
                    return;
                }
                throw new LogicException('Direct SQL writes to inpatient location history tables are prohibited.');
            }
        }

        $encounterTarget = '(?:[a-z0-9_]+\s*\.\s*)?encounters';
        $updatesEncounter = preg_match(
            '/\bupdate\s+(?:(?:low_priority|ignore|only)\s+)*'.$encounterTarget.'\b/is',
            $normalized,
        ) === 1 || preg_match('/\bmerge\s+into\s+(?:only\s+)?'.$encounterTarget.'\b/is', $normalized) === 1;
        $replacesEncounter = preg_match(
            '/\breplace\s+(?:(?:low_priority|delayed)\s+)*(?:into\s+)?'.$encounterTarget.'\b/is',
            $normalized,
        ) === 1;
        $insertsEncounter = preg_match(
            '/\binsert\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:into\s+)?(?:only\s+)?'.$encounterTarget.'\b/is',
            $normalized,
        ) === 1;
        $upsertsEncounter = $insertsEncounter
            && (preg_match('/\bon\s+conflict\b.*\bdo\s+update\b/is', $normalized) === 1
                || preg_match('/\bon\s+duplicate\s+key\s+update\b/is', $normalized) === 1);
        $deletesEncounter = false;
        foreach (preg_split('/;/', $normalized) ?: [] as $statement) {
            $isDeleteStatement = preg_match('/^\s*delete\b/is', $statement) === 1
                || (preg_match('/^\s*with\b/is', $statement) === 1
                    && preg_match('/\bdelete\b/is', $statement) === 1);
            if (! $isDeleteStatement) {
                continue;
            }
            preg_match_all('/\bdelete\b/is', $statement, $deleteMatches, PREG_OFFSET_CAPTURE);
            $lastDelete = end($deleteMatches[0]);
            $deleteClause = is_array($lastDelete) ? substr($statement, $lastDelete[1]) : $statement;
            $topLevelHead = preg_split('/\bwhere\b/is', $deleteClause, 2)[0] ?? $deleteClause;
            if (preg_match('/\b'.$encounterTarget.'\b/is', $topLevelHead) === 1) {
                $deletesEncounter = true;
                break;
            }
        }
        if ($deletesEncounter) {
            throw new LogicException('Direct SQL deletion of encounters is prohibited.');
        }
        if ($replacesEncounter) {
            throw new LogicException('Direct SQL replacement of encounters is prohibited.');
        }
        if ($updatesEncounter || $upsertsEncounter) {
            foreach (['inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class', 'clinic_name'] as $column) {
                if (preg_match('/\b'.preg_quote($column, '/').'\b/is', $normalized) === 1) {
                    throw new LogicException('Direct SQL mutation of inpatient encounter placement is prohibited.');
                }
            }
        }
        if ($insertsEncounter) {
            $hasExplicitColumnList = preg_match(
                '/\binsert\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:into\s+)?(?:only\s+)?'.$encounterTarget.'\s*\(/is',
                $normalized,
            ) === 1;
            if (! $hasExplicitColumnList) {
                throw new LogicException('Encounter inserts require an explicit safe column list.');
            }
            foreach (['inpatient_bed_id', 'bed_code', 'ward_name', 'ward_class'] as $column) {
                if (preg_match('/\b'.preg_quote($column, '/').'\b/is', $normalized) === 1) {
                    throw new LogicException('Direct SQL creation of inpatient encounter placement is prohibited.');
                }
            }
        }
    }

    private function ddlTarget(string $normalized): ?string
    {
        if (preg_match('/^\s*(?:create|drop)\s+trigger\b.*?\bon\s+([a-z0-9_.]+)/i', $normalized, $matches) === 1) {
            $parts = explode('.', $matches[1]);

            return end($parts) ?: null;
        }

        if (preg_match('/^\s*create\s+(?:unique\s+)?index\s+[a-z0-9_.]+\s+on\s+([a-z0-9_.]+)/i', $normalized, $matches) === 1) {
            $parts = explode('.', $matches[1]);

            return end($parts) ?: null;
        }

        if (preg_match('/^\s*(?:create\s+table(?:\s+if\s+not\s+exists)?|alter\s+table|drop\s+table(?:\s+if\s+exists)?|rename\s+table)\s+([a-z0-9_.]+)/i', $normalized, $matches) !== 1) {
            return null;
        }

        $parts = explode('.', $matches[1]);

        return end($parts) ?: null;
    }
}
