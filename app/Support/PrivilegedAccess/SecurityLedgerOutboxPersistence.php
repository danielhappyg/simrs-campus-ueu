<?php

namespace App\Support\PrivilegedAccess;

use App\Models\SecurityLedgerEntry;
use App\Models\SecurityLedgerOutbox;

class SecurityLedgerOutboxPersistence
{
    public const DEFAULT_DESTINATION = 'protected-security-sink';

    public function persist(SecurityLedgerEntry $entry): SecurityLedgerOutbox
    {
        return SecurityLedgerOutbox::query()->create([
            'security_ledger_entry_id' => $entry->getKey(),
            'destination' => self::DEFAULT_DESTINATION,
            'delivery_state' => SecurityLedgerOutbox::STATE_PENDING,
            'attempts' => 0,
            'available_at' => $entry->recorded_at,
        ]);
    }
}
