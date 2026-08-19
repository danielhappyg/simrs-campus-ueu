<?php

namespace App\Modules\Claims\Contracts;

use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Claims\Models\EClaimCase;

interface EClaimGateway
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function exchange(EClaimAction $action, array $request, EClaimCase $case): array;
}
