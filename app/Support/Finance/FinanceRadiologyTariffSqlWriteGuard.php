<?php

namespace App\Support\Finance;

use LogicException;

final class FinanceRadiologyTariffSqlWriteGuard
{
    /** @var list<string> */
    private const TABLES = [
        'finance_radiology_tariff_bindings',
        'finance_radiology_tariff_binding_versions',
        'finance_radiology_tariff_operation_receipts',
        'finance_radiology_source_events',
    ];

    public function assertAllowed(string $sql): void
    {
        if (FinanceRadiologyTariffMutationScope::isActive()
            || FinanceTariffSchemaMutationScope::isActive()
            || FinanceSchemaMutationScope::isActive()
            || FinanceMutationScope::isActive()) {
            return;
        }

        $raw = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $sql));
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $raw) ?? $raw;
        if (preg_match('/\/\*!|\/\*\+/', $withoutLiterals) === 1) {
            throw new LogicException('Ambiguous or executable-comment SQL against radiology tariff tables is prohibited.');
        }
        $normalized = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*(?![!+])[\s\S]*?\*\//', ' ', $withoutLiterals) ?? $withoutLiterals;
        if (! collect(self::TABLES)->contains(
            fn (string $table): bool => preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1,
        )) {
            return;
        }
        $trimmed = rtrim(trim($normalized), ';');
        if (str_contains($trimmed, ';')) {
            throw new LogicException('Ambiguous or executable-comment SQL against radiology tariff tables is prohibited.');
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $normalized) === 1) {
            throw new LogicException('Direct SQL writes to radiology tariff tables are prohibited.');
        }
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $normalized) !== 1) {
            throw new LogicException('Only read SQL is allowed against radiology tariff tables outside a governed scope.');
        }
    }
}
