<?php

namespace App\Support\Pharmacy;

use Illuminate\Database\Eloquent\Model;

final readonly class PharmacyMutationResult
{
    public function __construct(public Model $record, public bool $replayed) {}
}
