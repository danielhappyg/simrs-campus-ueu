<?php

namespace App\Support\Finance;

use LogicException;

final class FinanceTariffSqlWriteGuard
{
    /** @var list<string> */
    private const TABLES = [
        'finance_cost_component_groups', 'finance_cost_component_group_versions',
        'finance_cost_components', 'finance_cost_component_versions',
        'finance_tariff_catalogues', 'finance_tariff_catalogue_versions',
        'finance_tariff_items', 'finance_tariff_item_versions',
        'finance_tariff_code_reservations', 'finance_tariff_operation_receipts',
    ];

    public function assertAllowed(string $sql): void
    {
        if (FinanceTariffMutationScope::isActive() || FinanceTariffSchemaMutationScope::isActive()) {
            return;
        }

        $raw = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $sql));
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $raw) ?? $raw;
        if (preg_match('/\/\*!|\/\*\+/', $withoutLiterals) === 1) {
            throw new LogicException('Ambiguous or executable-comment SQL against finance tariff tables is prohibited.');
        }
        $normalized = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*(?![!+])[\s\S]*?\*\//', ' ', $withoutLiterals) ?? $withoutLiterals;
        $targetsProtected = collect(self::TABLES)->contains(
            fn (string $table): bool => preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1,
        );
        if (! $targetsProtected) {
            return;
        }
        $trimmed = rtrim(trim($normalized), ';');
        if (str_contains($trimmed, ';')) {
            throw new LogicException('Ambiguous or executable-comment SQL against finance tariff tables is prohibited.');
        }
        $ddlTargets = $this->ddlTargets($normalized);
        if ($ddlTargets !== [] && collect($ddlTargets)->every(fn (string $target): bool => ! in_array($target, self::TABLES, true))) {
            return;
        }
        $writeScan = $normalized;
        if (preg_match('/\A\s*(?:select|with)\b/', $normalized) === 1) {
            $writeScan = preg_replace('/\bfor\s+(?:no\s+key\s+)?update\b/', 'for locking_read', $normalized)
                ?? $normalized;
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $writeScan) === 1) {
            throw new LogicException('Direct SQL writes to finance tariff master tables are prohibited.');
        }
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $normalized) !== 1) {
            throw new LogicException('Only read SQL is allowed against finance tariff tables outside the governed scope.');
        }
    }

    /** @return list<string> */
    private function ddlTargets(string $sql): array
    {
        $patterns = [
            '/^\s*(?:create|drop)\s+trigger\b.*?\bon\s+([a-z0-9_.]+)/i',
            '/^\s*create\s+(?:unique\s+)?index\s+[a-z0-9_.]+\s+on\s+([a-z0-9_.]+)/i',
            '/^\s*(?:create\s+table(?:\s+if\s+not\s+exists)?|alter\s+table|drop\s+table(?:\s+if\s+exists)?)\s+([a-z0-9_.]+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches) === 1) {
                return [$this->unqualify($matches[1])];
            }
        }
        if (preg_match('/^\s*rename\s+table\s+([a-z0-9_.]+)\s+to\s+([a-z0-9_.]+)/i', $sql, $matches) === 1) {
            return [$this->unqualify($matches[1]), $this->unqualify($matches[2])];
        }

        return [];
    }

    private function unqualify(string $identifier): string
    {
        $parts = explode('.', $identifier);

        return end($parts) ?: $identifier;
    }
}
