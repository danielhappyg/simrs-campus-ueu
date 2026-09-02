<?php

namespace App\Support\Finance;

use LogicException;

final class FinanceSqlWriteGuard
{
    private const TABLES = [
        'finance_charge_events', 'finance_bills', 'finance_bill_versions', 'finance_bill_lines',
        'finance_operation_receipts', 'finance_cash_settlements',
        'finance_settlement_operation_receipts',
        'finance_settlement_correction_cases', 'finance_settlement_correction_events',
        'finance_settlement_correction_operation_receipts',
        'finance_cashier_collection_batches', 'finance_cashier_collection_active_slots',
        'finance_cashier_collection_members', 'finance_cashier_collection_events',
        'finance_cash_deposit_handoffs', 'finance_cashier_collection_operation_receipts',
    ];

    public function assertAllowed(string $sql): void
    {
        if (FinanceMutationScope::isActive() || FinanceSchemaMutationScope::isActive()) {
            return;
        }
        $raw = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $sql));
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $raw) ?? $raw;
        if (preg_match('/\/\*!|\/\*\+/', $withoutLiterals) === 1) {
            throw new LogicException('Ambiguous or executable-comment SQL against finance tables is prohibited.');
        }
        $normalized = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*(?![!+])[\s\S]*?\*\//', ' ', $withoutLiterals) ?? $withoutLiterals;
        if (! collect(self::TABLES)->contains(fn (string $table): bool => preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1)) {
            return;
        }
        $trimmed = rtrim(trim($normalized), ';');
        if (str_contains($trimmed, ';')) {
            throw new LogicException('Ambiguous or executable-comment SQL against finance tables is prohibited.');
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $normalized) === 1) {
            throw new LogicException('Write-capable SQL against finance tables is prohibited.');
        }
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $normalized) !== 1) {
            throw new LogicException('Only read SQL is allowed against finance tables outside the governed scope.');
        }
    }
}
