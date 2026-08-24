<?php

namespace App\Support\Clinical;

use RuntimeException;

final class OutpatientLifecycleDenial extends RuntimeException
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message);
    }
}
