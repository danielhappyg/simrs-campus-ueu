<?php

namespace App\Support\Warehouse;

use App\Models\User;
use App\Support\Database\SchemaQualifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use LogicException;

final class WarehouseActorTransactionFence
{
    public function __construct(private readonly WarehouseActorPolicy $policy) {}

    public function assertAuthorized(
        Connection $connection,
        User $actor,
        string $operation,
        WarehouseOperationAuthorization $authorization,
    ): void {
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Warehouse actor fencing requires an active writer transaction.');
        }
        if (! $authorization->matches($actor, $operation)) {
            throw new AuthorizationException;
        }

        match ($connection->getDriverName()) {
            'sqlite' => $this->assertSqlite($connection, $authorization),
            'pgsql', 'mysql' => $this->assertExactEngine($connection, $authorization),
            default => throw new LogicException('Warehouse actor fencing is unsupported for this database driver.'),
        };
    }

    private function assertSqlite(Connection $connection, WarehouseOperationAuthorization $authorization): void
    {
        $users = SchemaQualifier::table('users');
        $locked = $connection->update(
            "UPDATE {$users} SET teaching_access_mutex = teaching_access_mutex WHERE id = ?",
            [$authorization->actorId],
        );
        if ($locked !== 1 || ! $this->policy->claimRemainsValid($authorization, $connection)) {
            throw new AuthorizationException;
        }
    }

    private function assertExactEngine(Connection $connection, WarehouseOperationAuthorization $authorization): void
    {
        // The configured security-definer function must take the ten ordered
        // claim fields below, acquire the authoritative actor/assignment/lease
        // locks, validate the operation-to-role/capability mapping itself, and
        // return true only while every value still matches in this transaction.
        $routine = config("database.connections.{$connection->getName()}.warehouse_actor_fence_routine");
        if (! is_string($routine)
            || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?\z/', $routine) !== 1) {
            throw new LogicException(
                'Exact-engine warehouse mutations require a configured, narrowly granted security-definer actor fence routine.',
            );
        }

        $quotedRoutine = collect(explode('.', $routine))
            ->map(fn (string $part): string => $connection->getQueryGrammar()->wrap($part))
            ->implode('.');
        $row = $connection->selectOne(
            "SELECT {$quotedRoutine}(?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS warehouse_actor_authorized",
            [
                $authorization->actorId,
                $authorization->actorPublicId,
                $authorization->operation,
                $authorization->role,
                $authorization->capability,
                $authorization->rosterAccount ? 1 : 0,
                $authorization->teachingAccessEpoch,
                $authorization->teachingAccessMutex,
                $authorization->leasePublicId,
                $authorization->requestHost,
            ],
        );
        $allowed = data_get($row, 'warehouse_actor_authorized');
        if (! in_array($allowed, [true, 1, '1', 't', 'true'], true)) {
            throw new AuthorizationException;
        }
    }
}
