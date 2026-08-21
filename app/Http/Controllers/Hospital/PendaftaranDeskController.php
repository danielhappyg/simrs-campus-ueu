<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Services\HospitalSessionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PendaftaranDeskController extends Controller
{
    public function __construct(
        private readonly HospitalSessionContext $sessionContext,
    ) {}

    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignments = $this->sessionContext->activeAssignments($user)
            ->filter(fn (Assignment $assignment): bool => $assignment->hasCapability(Capability::PatientRegister));

        if ($assignments->isEmpty()) {
            abort(403);
        }

        $session = $this->sessionContext->resolveSession(
            $assignments,
            is_string($request->query('session')) ? $request->query('session') : null,
            requireExplicitWhenMultiple: true,
        );

        if ($session === null) {
            return Inertia::render('hospital/session-picker', [
                'module' => [
                    'key' => 'pendaftaran',
                    'title' => 'Pendaftaran',
                    'description' => 'Pilih sesi simulasi untuk membuka meja pendaftaran.',
                ],
                'sessions' => $this->sessionContext->sessionOptions($assignments),
                'continueBaseUrl' => route('desk.pendaftaran'),
            ]);
        }

        return redirect()->route('sessions.registration', $session);
    }
}
