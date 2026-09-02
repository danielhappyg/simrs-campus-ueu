<?php

namespace App\Support\Finance;

use LogicException;

final class FinanceLaboratoryTariffSqlWriteGuard
{
    /** @var list<string> */
    private const TABLES = [
        'finance_laboratory_tariff_bindings',
        'finance_laboratory_tariff_binding_versions',
        'finance_laboratory_tariff_operation_receipts',
        'finance_laboratory_source_events',
    ];

    public function assertAllowed(string $sql): void
    {
        if (FinanceLaboratoryTariffMutationScope::isActive()
            || FinanceTariffSchemaMutationScope::isActive()
            || FinanceSchemaMutationScope::isActive()) {
            return;
        }

        $raw = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $sql));
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $raw) ?? $raw;
        if (preg_match('/\/\*!|\/\*\+/', $withoutLiterals) === 1) {
            throw new LogicException('Ambiguous or executable-comment SQL against laboratory tariff tables is prohibited.');
        }
        $normalized = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*(?![!+])[\s\S]*?\*\//', ' ', $withoutLiterals) ?? $withoutLiterals;
        $targets = collect(self::TABLES)->filter(
            fn (string $table): bool => preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1,
        )->values();
        if ($targets->isEmpty()) {
            return;
        }
        if (FinanceMutationScope::isActive()
            && $targets->every(fn (string $table): bool => $table === 'finance_laboratory_source_events')) {
            return;
        }
        $trimmed = rtrim(trim($normalized), ';');
        if (str_contains($trimmed, ';')) {
            throw new LogicException('Ambiguous or executable-comment SQL against laboratory tariff tables is prohibited.');
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $normalized) === 1) {
            throw new LogicException('Direct SQL writes to laboratory tariff tables are prohibited.');
        }
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $normalized) !== 1) {
            throw new LogicException('Only read SQL is allowed against laboratory tariff tables outside a governed scope.');
        }
    }
}
