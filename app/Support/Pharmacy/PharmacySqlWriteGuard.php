<?php

namespace App\Support\Pharmacy;

use LogicException;

final class PharmacySqlWriteGuard
{
    private const TABLES = [
        'pharmacy_medicine_code_reservations', 'pharmacy_medicines', 'pharmacy_medicine_versions',
        'pharmacy_depot_code_reservations', 'pharmacy_depots', 'pharmacy_depot_versions',
        'pharmacy_inventory_mutexes', 'pharmacy_stock_lots', 'pharmacy_prescriptions',
        'pharmacy_prescription_versions', 'pharmacy_prescription_items', 'pharmacy_verifications',
        'pharmacy_preparations', 'pharmacy_preparation_allocations', 'pharmacy_handovers',
        'pharmacy_handover_items', 'pharmacy_stock_movements', 'pharmacy_financial_source_events',
        'pharmacy_returns', 'pharmacy_return_items', 'pharmacy_operation_receipts',
    ];

    public function assertAllowed(string $sql): void
    {
        if (PharmacyMutationScope::isActive() || PharmacySchemaMutationScope::isActive()) {
            return;
        }

        $raw = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $sql));
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $raw) ?? $raw;
        if (preg_match('/\/\*!|\/\*\+/', $withoutLiterals) === 1) {
            throw new LogicException('Ambiguous or executable-comment SQL against pharmacy tables is prohibited.');
        }
        $normalized = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*(?![!+])[\s\S]*?\*\//', ' ', $withoutLiterals) ?? $withoutLiterals;
        if (! collect(self::TABLES)->contains(fn (string $table): bool => preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1)) {
            return;
        }

        $trimmed = rtrim(trim($normalized), ';');
        if (str_contains($trimmed, ';')) {
            throw new LogicException('Ambiguous or executable-comment SQL against pharmacy tables is prohibited.');
        }
        $ddlTarget = $this->ddlTarget($normalized);
        if ($ddlTarget !== null && ! in_array($ddlTarget, self::TABLES, true)) {
            return;
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $normalized) === 1) {
            throw new LogicException('Write-capable SQL against pharmacy tables is prohibited.');
        }
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $normalized) !== 1) {
            throw new LogicException('Only read SQL is allowed against pharmacy tables outside the governed scope.');
        }
    }

    private function ddlTarget(string $sql): ?string
    {
        $patterns = [
            '/^\s*(?:create|drop)\s+trigger\b.*?\bon\s+([a-z0-9_.]+)/i',
            '/^\s*create\s+(?:unique\s+)?index\s+[a-z0-9_.]+\s+on\s+([a-z0-9_.]+)/i',
            '/^\s*(?:create\s+table(?:\s+if\s+not\s+exists)?|alter\s+table|drop\s+table(?:\s+if\s+exists)?|rename\s+table)\s+([a-z0-9_.]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches) === 1) {
                $parts = explode('.', $matches[1]);

                return end($parts) ?: null;
            }
        }

        return null;
    }
}
