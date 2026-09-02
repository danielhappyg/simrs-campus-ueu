<?php

namespace App\Support\Finance;

use Illuminate\Database\Eloquent\Model;

final readonly class FinanceTariffMutationResult
{
    public function __construct(public Model $record, public bool $replayed) {}
}
