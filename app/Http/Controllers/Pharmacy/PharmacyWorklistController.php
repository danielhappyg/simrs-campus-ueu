<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\PharmacyPrescription;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Pharmacy\PharmacyActorPolicy;
use App\Support\Pharmacy\PharmacyProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PharmacyWorklistController extends Controller
{
    public function __construct(
        private readonly PharmacyActorPolicy $policy,
        private readonly PharmacyProjection $projection,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->authorizeGlobalWorklist($actor);

        return Inertia::render('apotek/resep/index', $this->projection->worklist($actor, $this->filters($request)));
    }

    public function history(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->authorizeGlobalWorklist($actor);

        return Inertia::render('apotek/riwayat/index', $this->projection->worklist($actor, $this->filters($request)));
    }

    public function show(Request $request, string $prescription): Response
    {
        $actor = $this->actor($request);
        $this->authorizeGlobalWorklist($actor);
        $record = PharmacyPrescription::query()->where('public_id', $prescription)->firstOrFail();
        $worklist = $this->projection->worklist($actor, ['q' => '__EMPTY_PHARMACY_PERMISSION_METADATA__']);

        return Inertia::render('apotek/resep/show', [
            'prescription' => $this->projection->prescription($record, $actor),
            'permissions' => $worklist['permissions'],
            'read_error' => null,
        ]);
    }

    public function stockCard(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->inventory($actor);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'medicine' => ['nullable', 'string', 'max:26'],
            'depot' => ['nullable', 'string', 'max:26'],
            'state' => ['nullable', Rule::in(['ACTIVE', 'QUARANTINED', 'RETIRED'])],
        ]);

        return Inertia::render('apotek/kartu-stok/index', $this->projection->stockCard($actor, $validated));
    }

    /** @return array{q:string,care_setting:string,state:string,depot:string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'care_setting' => ['nullable', Rule::in(Encounter::CARE_SETTINGS)],
            'state' => ['nullable', Rule::in(['DRAFT', 'ORDERED', 'VERIFIED', 'REFUSED', 'PREPARED', 'PARTIALLY_HANDED_OVER', 'HANDED_OVER', 'UNFILLED_CLOSED', 'CANCELLED'])],
            'depot' => ['nullable', 'string', 'max:26'],
        ]);

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'care_setting' => (string) ($validated['care_setting'] ?? ''),
            'state' => (string) ($validated['state'] ?? ''),
            'depot' => (string) ($validated['depot'] ?? ''),
        ];
    }

    private function authorizeGlobalWorklist(User $actor): void
    {
        foreach ([RoleCapabilityMatrix::ROLE_PHARMACIST, RoleCapabilityMatrix::ROLE_PHARMACY_TECHNICIAN] as $role) {
            if ($this->policy->can($actor, $role, Capability::PHARMACY_PRESCRIPTION_VIEW)) {
                return;
            }
        }

        throw new AuthorizationException;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
