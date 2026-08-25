<?php

namespace App\Support\PrivilegedAccess;

final class SecurityLedgerActorGuard
{
    /** @var list<string> */
    private const SERVICE_REFERENCES = [
        'break-glass-expiry-sweeper',
        'break-glass-recovery-service',
        'security-ledger-outbox-worker',
    ];

    public function assertValid(SecurityLedgerRecord $record): void
    {
        if ($record->actorType === 'USER') {
            if (
                $record->actor === null
                || ! $record->actor->exists
                || $record->actor->getKey() === null
                || ! hash_equals((string) $record->actor->public_id, $record->actorReference)
            ) {
                throw new UnsafeSecurityLedgerPayload(
                    'USER ledger actors require the exact persisted actor foreign key and public ID.',
                );
            }

            return;
        }

        if ($record->actorType === 'SERVICE') {
            if (
                $record->actor !== null
                || ! in_array($record->actorReference, self::SERVICE_REFERENCES, true)
            ) {
                throw new UnsafeSecurityLedgerPayload(
                    'SERVICE ledger actors require a null user and a registered service reference.',
                );
            }

            return;
        }

        throw new UnsafeSecurityLedgerPayload('Security-ledger actor type is not registered.');
    }
}
