<?php

namespace App\Http\Controllers\Rebuild;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class RebuildHomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('rebuild/home', [
            'phase' => '0/2',
            'branch' => 'rebuild/clean-slate',
            'docsPath' => 'docs/new-simrs-rebuild/',
            'mode' => config('simulation.mode'),
            'syntheticOnly' => (bool) config('simulation.synthetic_only'),
        ]);
    }
}
