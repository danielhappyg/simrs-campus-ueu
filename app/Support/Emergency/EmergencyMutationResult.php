<?php

namespace App\Support\Emergency;

use Illuminate\Database\Eloquent\Model;

final readonly class EmergencyMutationResult
{
    public function __construct(public Model $record, public bool $replayed) {}
}
