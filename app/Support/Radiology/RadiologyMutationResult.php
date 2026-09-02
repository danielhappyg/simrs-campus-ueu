<?php

namespace App\Support\Radiology;

use Illuminate\Database\Eloquent\Model;

final readonly class RadiologyMutationResult
{
    public function __construct(public Model $record, public bool $replayed) {}
}
