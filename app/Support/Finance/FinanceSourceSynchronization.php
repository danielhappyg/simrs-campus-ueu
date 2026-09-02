<?php

namespace App\Support\Finance;

use App\Models\FinanceChargeEvent;
use Illuminate\Support\Collection;

final readonly class FinanceSourceSynchronization
{
    /**
     * @param  Collection<int, FinanceChargeEvent>  $events
     * @param  list<array<string, mixed>>  $readiness
     */
    public function __construct(
        public Collection $events,
        public array $readiness,
    ) {}

    /** @return list<array<string, mixed>> */
    public function unresolved(): array
    {
        return array_values(array_filter(
            $this->readiness,
            static fn (array $item): bool => ! in_array(
                $item['state'] ?? null,
                ['SIAP_DISINKRONKAN', 'TERSINKRONISASI'],
                true,
            ),
        ));
    }

    public function hasUnresolved(): bool
    {
        return $this->unresolved() !== [];
    }
}
