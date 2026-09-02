<?php

namespace App\Support\Inpatient;

use LogicException;

final class InpatientDocumentationSqlWriteGuard
{
    /** @var list<string> */
    private const PROTECTED_TABLES = [
        'inpatient_clinical_documents',
        'inpatient_clinical_document_versions',
        'inpatient_document_operation_receipts',
        'inpatient_discharge_summaries',
        'inpatient_discharge_summary_versions',
        'inpatient_discharge_summary_operation_receipts',
        'inpatient_discharge_coding_sources',
        'inpatient_discharge_coding_source_versions',
        'inpatient_discharge_coding_source_operation_receipts',
        'inpatient_discharges',
        'inpatient_discharge_operation_receipts',
        'inpatient_rm_codings',
        'inpatient_rm_coding_versions',
        'inpatient_rm_coding_assignments',
        'inpatient_rm_completeness_reviews',
        'inpatient_rm_completeness_items',
        'inpatient_rm_operation_receipts',
        'inpatient_summary_correction_requests',
        'inpatient_summary_correction_request_versions',
        'inpatient_summary_addenda',
        'inpatient_summary_addendum_versions',
        'inpatient_summary_addendum_reviews',
        'inpatient_summary_addendum_review_items',
        'inpatient_summary_addendum_operation_receipts',
    ];

    public function assertAllowed(string $sql): void
    {
        if (preg_match('/\/\*(?:!|m!)/i', $sql) === 1) {
            throw new LogicException('Executable SQL comments are prohibited on inpatient documentation write paths.');
        }
        $withoutComments = preg_replace('/\/\*.*?\*\/|--[^\r\n]*/s', ' ', $sql) ?? $sql;
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $withoutComments) ?? $withoutComments;
        $normalized = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $withoutLiterals));
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
        if (! $isDdl && ! $isDml) {
            return;
        }

        $target = $this->writeTarget($normalized, $isDdl);
        if ($target !== null && in_array($target, self::PROTECTED_TABLES, true)) {
            $sqliteShadowCopy = $isDml
                && preg_match('/^\s*insert\s+into\s+__temp__'.preg_quote($target, '/').'\b/i', $normalized) === 1;
            if (($isDdl && $this->schemaScopeAllows($target))
                || ($isDml && $this->mutationScopeAllows($target))
                || ($sqliteShadowCopy && $this->schemaScopeAllows($target))) {
                return;
            }
            throw new LogicException("Direct SQL writes to inpatient documentation table [{$target}] are prohibited.");
        }

        if ($target !== null && ! $hasFollowingStatement) {
            // A foreign key or check may reference protected evidence while the
            // statement writes only to a different domain's known target.
            return;
        }

        foreach (self::PROTECTED_TABLES as $table) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1) {
                throw new LogicException('Unscoped or ambiguous SQL writes referencing inpatient documentation tables are prohibited.');
            }
        }
    }

    private function writeTarget(string $normalized, bool $isDdl): ?string
    {
        if ($isDdl && preg_match('/^\s*create\s+(?:unique\s+)?index\s+[a-z0-9_.]+\s+on\s+([a-z0-9_.]+)/i', $normalized, $matches) === 1) {
            $parts = explode('.', $matches[1]);

            return $this->canonicalTarget(end($parts) ?: null);
        }
        if ($isDdl && preg_match('/^\s*(?:create|drop)\s+trigger\b.*?\bon\s+([a-z0-9_.]+)/i', $normalized, $matches) === 1) {
            $parts = explode('.', $matches[1]);

            return $this->canonicalTarget(end($parts) ?: null);
        }
        if ($isDdl && preg_match('/^\s*drop\s+index\b.*?\bon\s+([a-z0-9_.]+)/i', $normalized, $matches) === 1) {
            $parts = explode('.', $matches[1]);

            return $this->canonicalTarget(end($parts) ?: null);
        }
        $pattern = $isDdl
            ? '/^\s*(?:create\s+table(?:\s+if\s+not\s+exists)?|alter\s+table|drop\s+table(?:\s+if\s+exists)?|rename\s+table)\s+([a-z0-9_.]+)/i'
            : '/^\s*(?:insert\s+into|replace\s+into|update|delete\s+from|truncate(?:\s+table)?|merge\s+into)\s+([a-z0-9_.]+)/i';
        if (preg_match($pattern, $normalized, $matches) !== 1) {
            return null;
        }
        $parts = explode('.', $matches[1]);

        return $this->canonicalTarget(end($parts) ?: null);
    }

    private function canonicalTarget(?string $target): ?string
    {
        if ($target !== null && str_starts_with($target, '__temp__')) {
            return substr($target, 8);
        }

        return $target;
    }

    private function mutationScopeAllows(string $table): bool
    {
        return match (true) {
            in_array($table, ['inpatient_clinical_documents', 'inpatient_clinical_document_versions', 'inpatient_document_operation_receipts'], true) => InpatientDocumentationMutationScope::isActive(),
            in_array($table, ['inpatient_discharge_summaries', 'inpatient_discharge_summary_versions', 'inpatient_discharge_summary_operation_receipts'], true) => InpatientDischargeSummaryMutationScope::isActive(),
            in_array($table, ['inpatient_discharge_coding_sources', 'inpatient_discharge_coding_source_versions', 'inpatient_discharge_coding_source_operation_receipts'], true) => InpatientDischargeCodingSourceMutationScope::isActive(),
            in_array($table, ['inpatient_discharges', 'inpatient_discharge_operation_receipts'], true) => InpatientDischargeMutationScope::isActive(),
            in_array($table, ['inpatient_rm_codings', 'inpatient_rm_coding_versions', 'inpatient_rm_coding_assignments', 'inpatient_rm_completeness_reviews', 'inpatient_rm_completeness_items', 'inpatient_rm_operation_receipts'], true) => InpatientRmMutationScope::isActive(),
            in_array($table, ['inpatient_summary_correction_requests', 'inpatient_summary_correction_request_versions', 'inpatient_summary_addenda', 'inpatient_summary_addendum_versions', 'inpatient_summary_addendum_reviews', 'inpatient_summary_addendum_review_items', 'inpatient_summary_addendum_operation_receipts'], true) => InpatientSummaryAddendumMutationScope::isActive(),
            default => false,
        };
    }

    private function schemaScopeAllows(string $table): bool
    {
        return match (true) {
            in_array($table, ['inpatient_clinical_documents', 'inpatient_clinical_document_versions', 'inpatient_document_operation_receipts'], true) => InpatientDocumentationSchemaMutationScope::isActive(),
            in_array($table, ['inpatient_discharge_summaries', 'inpatient_discharge_summary_versions', 'inpatient_discharge_summary_operation_receipts'], true) => InpatientDischargeSummarySchemaMutationScope::isActive(),
            in_array($table, ['inpatient_discharge_coding_sources', 'inpatient_discharge_coding_source_versions', 'inpatient_discharge_coding_source_operation_receipts'], true) => InpatientDischargeCodingSourceSchemaMutationScope::isActive(),
            in_array($table, ['inpatient_discharges', 'inpatient_discharge_operation_receipts'], true) => InpatientDischargeSchemaMutationScope::isActive()
                || InpatientDischargeCodingSourceSchemaMutationScope::isActive(),
            in_array($table, ['inpatient_rm_codings', 'inpatient_rm_coding_versions', 'inpatient_rm_coding_assignments', 'inpatient_rm_completeness_reviews', 'inpatient_rm_completeness_items', 'inpatient_rm_operation_receipts'], true) => InpatientRmSchemaMutationScope::isActive(),
            in_array($table, ['inpatient_summary_correction_requests', 'inpatient_summary_correction_request_versions', 'inpatient_summary_addenda', 'inpatient_summary_addendum_versions', 'inpatient_summary_addendum_reviews', 'inpatient_summary_addendum_review_items', 'inpatient_summary_addendum_operation_receipts'], true) => InpatientSummaryAddendumSchemaMutationScope::active(),
            default => false,
        };
    }
}
