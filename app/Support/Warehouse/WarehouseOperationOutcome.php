<?php

namespace App\Support\Warehouse;

use Illuminate\Database\Eloquent\Model;

final readonly class WarehouseOperationOutcome
{
    /**
     * @param  array<string, mixed>  $auditMetadata
     */
    public function __construct(
        public Model $record,
        public int $version,
        public string $state,
        public string $contentDigest,
        public array $auditMetadata,
        public int $controlTotal = 0,
    ) {}
}
