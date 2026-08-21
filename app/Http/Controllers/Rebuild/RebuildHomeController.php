<?php

namespace App\Http\Controllers\Rebuild;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class RebuildHomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('rebuild/home');
    }
}
