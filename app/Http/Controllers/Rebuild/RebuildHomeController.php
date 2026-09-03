<?php

namespace App\Http\Controllers\Rebuild;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Home\HomeDeskProjection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RebuildHomeController extends Controller
{
    public function __construct(
        private readonly HomeDeskProjection $homeDesk,
    ) {}

    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return Inertia::render('rebuild/home', $this->homeDesk->forActor($actor));
    }
}
