<?php

namespace App\Support\Laboratory;

use Illuminate\Database\Eloquent\Model;

final readonly class LaboratoryMutationResult
{
    public function __construct(public Model $record, public bool $replayed) {}
}
