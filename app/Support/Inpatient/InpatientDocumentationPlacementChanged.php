<?php

namespace App\Support\Inpatient;

use RuntimeException;

/** Internal retry signal used to restart with the encounter's current placement. */
final class InpatientDocumentationPlacementChanged extends RuntimeException {}
