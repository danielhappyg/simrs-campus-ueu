<?php

namespace App\Support\Warehouse;

use App\Support\Finance\FinanceAccommodationTariffAppendOnlyGuard;
use App\Support\Finance\FinanceAppendOnlyGuard;
use App\Support\Finance\FinanceLaboratoryTariffAppendOnlyGuard;
use App\Support\Finance\FinanceRadiologyTariffAppendOnlyGuard;
use App\Support\Finance\FinanceTariffAppendOnlyGuard;
use App\Support\Pharmacy\PharmacyAppendOnlyGuard;
use App\Support\Pharmacy\PharmacyMutationScope;
use App\Support\Pharmacy\PharmacySchemaMutationScope;
use App\Support\Simulation\SyntheticResetService;
use Illuminate\Database\Connection;
use LogicException;

final class WarehouseSqlWriteGuard
{
    /** @var list<class-string> */
    private const TRUSTED_SYNTHETIC_RESET_CALLERS = [
        FinanceAccommodationTariffAppendOnlyGuard::class,
        FinanceAppendOnlyGuard::class,
        FinanceLaboratoryTariffAppendOnlyGuard::class,
        FinanceRadiologyTariffAppendOnlyGuard::class,
        FinanceTariffAppendOnlyGuard::class,
        PharmacyAppendOnlyGuard::class,
        SyntheticResetService::class,
    ];

    /** @var list<string> */
    private const PHARMACY_TABLES = [
        'pharmacy_depots', 'pharmacy_depot_versions', 'pharmacy_stock_lots', 'pharmacy_stock_movements',
    ];

    /** @var list<string> */
    private const TABLES = [
        'pharmacy_depots', 'pharmacy_depot_versions', 'pharmacy_stock_lots', 'pharmacy_stock_movements',
        'warehouse_suppliers', 'warehouse_supplier_versions', 'warehouse_purchase_orders',
        'warehouse_purchase_order_versions', 'warehouse_purchase_order_lines', 'warehouse_purchase_order_decisions',
        'warehouse_receipts', 'warehouse_receipt_lines', 'warehouse_custody_lots',
        'warehouse_custody_receipt_allocations', 'warehouse_custody_movement_sets',
        'warehouse_custody_movement_pairs',
        'warehouse_transfers', 'warehouse_transfer_items', 'warehouse_transfer_decisions',
        'warehouse_supplier_returns', 'warehouse_supplier_return_items', 'warehouse_supplier_return_decisions',
        'warehouse_unit_returns', 'warehouse_unit_return_items', 'warehouse_unit_return_decisions',
        'warehouse_correction_requests', 'warehouse_correction_decisions',
        'warehouse_correction_compensations', 'warehouse_operation_receipts',
    ];

    /** @param array<int|string, mixed> $bindings */
    public function assertAllowed(string $sql, ?Connection $connection = null, array $bindings = []): void
    {
        $warehouseMutationScopeActive = WarehouseMutationScope::isActive();
        $warehouseSchemaScopeActive = WarehouseSchemaMutationScope::isActive();

        $raw = mb_strtolower(str_replace(['`', '"', '[', ']'], '', $sql));
        $withoutLiterals = preg_replace("/'(?:''|\\\\.|[^'])*'/s", "''", $raw) ?? $raw;
        if (preg_match('/\/\*!|\/\*\+/', $withoutLiterals) === 1) {
            throw new LogicException('Ambiguous or executable-comment SQL against warehouse custody tables is prohibited.');
        }
        $normalized = preg_replace('/--[^\r\n]*|#[^\r\n]*|\/\*(?![!+])[\s\S]*?\*\//', ' ', $withoutLiterals) ?? $withoutLiterals;
        $this->assertWarehouseAuditSqlAllowed($raw, $normalized, $bindings);
        if (preg_match('/\A\s*set\s+(?:(?:default|local|session)\s+)?role\b|\A\s*set\s+session\s+authorization\b/', $normalized) === 1) {
            throw new LogicException('Direct warehouse database identity changes are prohibited.');
        }
        if (preg_match('/\A\s*set\s+(?:(?:local|session)\s+)?(?:simrs\.[a-z0-9_.]+|@+simrs_[a-z0-9_]+)\b/', $normalized) === 1) {
            if ((PharmacyMutationScope::isActive() || PharmacySchemaMutationScope::isActive())
                && preg_match('/\A\s*set\s+(?:(?:local|session)\s+)?(?:simrs\.pharmacy_mutation|@+simrs_pharmacy_mutation)\b/', $normalized) === 1) {
                return;
            }
            if ($this->isTrustedSyntheticResetStatement($normalized)) {
                return;
            }

            throw new LogicException('Direct warehouse guard session-variable changes are prohibited.');
        }
        $matchedTables = collect(self::TABLES)
            ->filter(fn (string $table): bool => preg_match('/\b'.preg_quote($table, '/').'\b/', $normalized) === 1)
            ->values();
        if ($matchedTables->isEmpty()) {
            return;
        }
        $trimmed = rtrim(trim($normalized), ';');
        if ($warehouseSchemaScopeActive) {
            $schemaDdl = preg_match('/\A\s*(?:create|alter|drop|rename)\b/', $normalized) === 1;
            $triggerDdl = preg_match('/\A\s*create\s+trigger\b[\s\S]*\bbegin\b[\s\S]*\bend\s*;?\s*\z/', $normalized) === 1;
            if (str_contains($trimmed, ';') && ! $triggerDdl) {
                throw new LogicException('Ambiguous or executable-comment SQL against warehouse custody tables is prohibited.');
            }
            $sqliteSchemaCopy = $connection instanceof Connection
                && $connection->getDriverName() === 'sqlite'
                && $this->isExactSqliteSchemaRebuildCopy($trimmed);
            if (! $schemaDdl && ! $sqliteSchemaCopy && ! $this->isReadOnlySql($normalized)) {
                throw new LogicException('Warehouse schema scope permits only schema DDL against protected tables.');
            }
            if ($connection instanceof Connection) {
                WarehouseMutationScope::assertExecutingScopeConnection($connection, true);
            }

            return;
        }
        $pharmacyOnly = $matchedTables->every(fn (string $table): bool => in_array($table, self::PHARMACY_TABLES, true));
        if ($pharmacyOnly && PharmacySchemaMutationScope::isActive()) {
            $schemaDdl = preg_match('/\A\s*(?:create|alter|drop|rename)\b/', $normalized) === 1;
            $triggerDdl = preg_match('/\A\s*create\s+trigger\b[\s\S]*\bbegin\b[\s\S]*\bend\s*;?\s*\z/', $normalized) === 1;
            if (str_contains($trimmed, ';') && ! $triggerDdl) {
                throw new LogicException('Ambiguous or executable-comment SQL against warehouse custody tables is prohibited.');
            }
            $sqliteSchemaCopy = $connection instanceof Connection
                && $connection->getDriverName() === 'sqlite'
                && $this->isExactSqliteSchemaRebuildCopy($trimmed);
            if (! $schemaDdl && ! $sqliteSchemaCopy && ! $this->isReadOnlySql($normalized)) {
                throw new LogicException('Pharmacy schema scope permits only schema DDL against protected tables.');
            }

            return;
        }
        if (str_contains($trimmed, ';')) {
            throw new LogicException('Ambiguous or executable-comment SQL against warehouse custody tables is prohibited.');
        }
        if ($pharmacyOnly && PharmacyMutationScope::isActive()) {
            if (preg_match('/\A\s*(?:create|alter|drop|rename)\b/', $normalized) === 1) {
                throw new LogicException('Pharmacy mutation scope cannot authorize schema DDL against protected tables.');
            }

            return;
        }
        if ($warehouseMutationScopeActive) {
            if ($connection instanceof Connection) {
                WarehouseMutationScope::assertExecutingScopeConnection($connection, false);
            }
            $this->assertWarehouseMutationDmlAllowed($normalized);

            return;
        }
        $ddlTargets = $this->ddlTargets($normalized);
        if ($ddlTargets !== [] && collect($ddlTargets)->every(fn (string $target): bool => ! in_array($target, self::TABLES, true))) {
            return;
        }
        $writeScan = $normalized;
        if (preg_match('/\A\s*(?:select|with)\b/', $normalized) === 1) {
            $writeScan = preg_replace('/\bfor\s+(?:no\s+key\s+)?update\b/', 'for locking_read', $normalized) ?? $normalized;
        }
        if (preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $writeScan) === 1) {
            throw new LogicException('Write-capable SQL against warehouse custody tables is prohibited.');
        }
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $normalized) !== 1) {
            throw new LogicException('Only read SQL is allowed against warehouse custody tables outside the governed scope.');
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

    private function isReadOnlySql(string $sql): bool
    {
        if (preg_match('/\A\s*(?:select|with|explain|pragma|show|describe)\b/', $sql) !== 1) {
            return false;
        }
        $writeScan = preg_replace('/\bfor\s+(?:no\s+key\s+)?update\b/', 'for locking_read', $sql) ?? $sql;

        return preg_match('/\b(?:insert|replace|update|delete|truncate|merge|create|alter|drop|rename|copy|grant|revoke|call|execute|vacuum|analyze)\b|\bload\s+data\b/', $writeScan) !== 1;
    }

    /** @param array<int|string, mixed> $bindings */
    private function assertWarehouseAuditSqlAllowed(string $raw, string $normalized, array $bindings): void
    {
        if (preg_match('/\baudit_events\b/', $normalized) !== 1) {
            return;
        }

        $ddl = preg_match('/\A\s*(?:create|alter|drop|rename)\b/', $normalized) === 1;
        if ($ddl && WarehouseMutationScope::isActive() && ! WarehouseSchemaMutationScope::isActive()) {
            throw new LogicException('Warehouse mutation scope cannot authorize audit schema DDL.');
        }
        if (preg_match('/\A\s*truncate\b/', $normalized) === 1) {
            throw new LogicException('Warehouse audit evidence cannot be truncated.');
        }

        $rowMutation = preg_match('/\b(?:update\s+(?:[a-z0-9_]+\.)?audit_events\b|delete\s+from\s+(?:[a-z0-9_]+\.)?audit_events\b)/', $normalized) === 1;
        if (! $rowMutation) {
            return;
        }
        if (str_contains(rtrim(trim($normalized), ';'), ';')) {
            throw new LogicException('Ambiguous warehouse audit mutation SQL is prohibited.');
        }
        if (str_contains($raw, WarehouseAuditEvidenceGuard::ACTION) || $this->bindingsContainWarehouseAuditAction($bindings)) {
            throw new LogicException('Direct warehouse audit evidence mutation is prohibited.');
        }
    }

    /** @param array<int|string, mixed> $bindings */
    private function bindingsContainWarehouseAuditAction(array $bindings): bool
    {
        foreach ($bindings as $binding) {
            if (is_array($binding) && $this->bindingsContainWarehouseAuditAction($binding)) {
                return true;
            }
            if (is_string($binding) && mb_strtolower($binding) === WarehouseAuditEvidenceGuard::ACTION) {
                return true;
            }
        }

        return false;
    }

    private function assertWarehouseMutationDmlAllowed(string $sql): void
    {
        if ($this->isReadOnlySql($sql)) {
            return;
        }

        $target = null;
        $operation = null;
        foreach ([
            'insert' => '/\A\s*insert(?:\s+or\s+ignore|\s+ignore)?\s+into\s+([a-z0-9_.]+)/',
            'update' => '/\A\s*update\s+([a-z0-9_.]+)/',
            'delete' => '/\A\s*delete\s+from\s+([a-z0-9_.]+)/',
        ] as $candidate => $pattern) {
            if (preg_match($pattern, $sql, $matches) === 1) {
                $operation = $candidate;
                $target = $this->unqualify($matches[1]);
                break;
            }
        }

        if ($target !== null && ! in_array($target, self::TABLES, true)) {
            return;
        }
        if ($target !== null
            && (($operation === 'insert'
                    && in_array($target, WarehouseAppendOnlyGuard::TABLES, true)
                    && preg_match('/\bon\s+conflict\b[\s\S]*\bdo\s+update\b|\bon\s+duplicate\s+key\s+update\b/', $sql) !== 1)
                || ($operation === 'delete'
                    && WarehouseAppendOnlyGuard::isSyntheticResetActive()
                    && in_array($target, WarehouseAppendOnlyGuard::TABLES, true))
                || (in_array($operation, ['insert', 'update', 'delete'], true) && in_array($target, WarehouseMutableHeadGuard::TABLES, true)))) {
            return;
        }

        throw new LogicException('Warehouse mutation scope permits only closed DML against protected tables.');
    }

    private function isExactSqliteSchemaRebuildCopy(string $sql): bool
    {
        if (preg_match('/\A\s*insert\s+into\s+__temp__(?<target>[a-z0-9_]+)\s*\([^;]+\)\s+select\s+[^;]+\s+from\s+(?:[a-z0-9_]+\.)?(?<source>[a-z0-9_]+)\s*\z/s', $sql, $matches) !== 1) {
            return false;
        }

        return $matches['target'] === $matches['source']
            && in_array($matches['target'], self::TABLES, true);
    }

    private function isTrustedSyntheticResetStatement(string $sql): bool
    {
        if (preg_match('/\A\s*set\s+(?:(?:local|session)\s+)?(?:simrs\.synthetic_reset|@+simrs_synthetic_reset)\b/', $sql) !== 1) {
            return false;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $frame) {
            $class = $frame['class'] ?? null;
            if (! is_string($class) || ! in_array($class, self::TRUSTED_SYNTHETIC_RESET_CALLERS, true)) {
                continue;
            }
            if ($class === SyntheticResetService::class
                || $frame['function'] === 'runSyntheticReset') {
                return true;
            }
        }

        return false;
    }
}
