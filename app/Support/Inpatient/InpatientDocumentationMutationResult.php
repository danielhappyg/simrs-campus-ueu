<?php

namespace App\Support\Inpatient;

use App\Models\InpatientClinicalDocument;
use App\Models\InpatientClinicalDocumentVersion;

final readonly class InpatientDocumentationMutationResult
{
    public function __construct(
        public InpatientClinicalDocument $document,
        public InpatientClinicalDocumentVersion $resultVersion,
        public bool $replayed,
    ) {}
}
