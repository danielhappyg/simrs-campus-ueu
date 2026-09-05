<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\User;
use App\Support\Pharmacy\PharmacyActorPolicy;
use App\Support\Pharmacy\PharmacyAuditUnavailable;
use App\Support\Pharmacy\PharmacyDenied;
use App\Support\Pharmacy\PharmacyMasterService;
use App\Support\Pharmacy\PharmacyProjection;
use App\Support\Pharmacy\PharmacyStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PharmacyMasterController extends Controller
{
    public function __construct(
        private readonly PharmacyActorPolicy $policy,
        private readonly PharmacyMasterService $masters,
        private readonly PharmacyStockService $stock,
        private readonly PharmacyProjection $projection,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->policy->inventory($actor);

        return Inertia::render('manajemen-data/apotek/index', $this->projection->masters($actor));
    }

    public function storeMedicine(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->medicineData($request, false);
        $this->run(fn () => $this->masters->createMedicine($actor, $data, $data['idempotency_key']));

        return back()->with('success', 'Master obat ditambahkan.');
    }

    public function updateMedicine(Request $request, string $medicine): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->medicineData($request, true);
        $this->run(fn () => $this->masters->reviseMedicine($medicine, $actor, $data['expected_version'], $data, $data['idempotency_key']));

        return back()->with('success', 'Medicine master version updated.');
    }

    public function retireMedicine(Request $request, string $medicine): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->medicineData($request, true);
        $data['state'] = 'RETIRED';
        $this->run(fn () => $this->masters->reviseMedicine($medicine, $actor, $data['expected_version'], $data, $data['idempotency_key']));

        return back()->with('success', 'Master obat dipensiunkan.');
    }

    public function storeDepot(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->depotData($request, false);
        $this->run(fn () => $this->masters->createDepot($actor, $data, $data['idempotency_key']));

        return back()->with('success', 'Depo ditambahkan.');
    }

    public function updateDepot(Request $request, string $depot): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->depotData($request, true);
        $this->run(fn () => $this->masters->reviseDepot($depot, $actor, $data['expected_version'], $data, $data['idempotency_key']));

        return back()->with('success', 'Depot version updated.');
    }

    public function retireDepot(Request $request, string $depot): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->depotData($request, true);
        $data['state'] = 'RETIRED';
        $this->run(fn () => $this->masters->reviseDepot($depot, $actor, $data['expected_version'], $data, $data['idempotency_key']));

        return back()->with('success', 'Depo dipensiunkan.');
    }

    public function storeLot(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'medicine_public_id' => ['required', 'string', 'size:26'],
            'depot_public_id' => ['required', 'string', 'size:26'],
            'lot_code' => ['required', 'string', 'min:2', 'max:80'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'expiry_date' => ['nullable', 'date'],
            'no_expiry_reason' => ['nullable', Rule::in(['NO_EXPIRY_ASSIGNED'])],
            'opening_quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'source_reference' => ['required', 'string', 'max:120'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        $this->run(fn () => $this->stock->openLot($actor, $data['medicine_public_id'], $data['depot_public_id'], $data, $data['idempotency_key']));

        return back()->with('success', 'Saldo awal lot dicatat.');
    }

    public function correctLot(Request $request, string $lot): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['available_delta' => ['required', 'integer'], 'quarantined_delta' => ['required', 'integer'], 'reason_code' => ['required', 'string', 'min:3', 'max:64'], 'idempotency_key' => $this->idempotencyRules()]);
        $this->run(fn () => $this->stock->correctLot($lot, $actor, $data['available_delta'], $data['quarantined_delta'], $data['reason_code'], $data['idempotency_key']));

        return back()->with('success', 'Koreksi stok dicatat sebagai pergerakan baru.');
    }

    public function quarantineLot(Request $request, string $lot): RedirectResponse
    {
        return $this->changeLotState($request, $lot, 'QUARANTINED', 'Lot dipindahkan ke karantina.');
    }

    public function releaseLot(Request $request, string $lot): RedirectResponse
    {
        return $this->changeLotState($request, $lot, 'ACTIVE', 'Lot dilepas dari karantina.');
    }

    /** @return array<string,mixed> */
    private function medicineData(Request $request, bool $revision): array
    {
        $data = $request->validate([
            'expected_version' => [$revision ? 'required' : 'nullable', 'integer', 'min:0'],
            'medicine_code' => [$revision ? 'nullable' : 'required', 'string', 'max:64'],
            'generic_name' => ['required', 'string', 'max:160'],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'strength_text' => ['required', 'string', 'max:160'],
            'dosage_form' => ['required', 'string', 'max:80'],
            'base_unit' => ['required', 'string', 'max:80'],
            'route_choices' => ['required'],
            'acquisition_value' => ['required', 'integer', 'min:0'],
            'teaching_sale_value' => ['required', 'integer', 'min:0'],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
        if (is_string($data['route_choices'])) {
            $data['route_choices'] = array_values(array_filter(array_map('trim', explode(',', $data['route_choices']))));
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function depotData(Request $request, bool $revision): array
    {
        return $request->validate([
            'expected_version' => [$revision ? 'required' : 'nullable', 'integer', 'min:0'],
            'depot_code' => [$revision ? 'nullable' : 'required', 'string', 'max:64'],
            'display_name' => ['required', 'string', 'max:160'],
            'eligible_care_settings' => ['required', 'array', 'min:1'],
            'eligible_care_settings.*' => ['required', Rule::in(Encounter::CARE_SETTINGS)],
            'idempotency_key' => $this->idempotencyRules(),
        ]);
    }

    private function changeLotState(Request $request, string $lot, string $state, string $message): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate(['reason_code' => ['required', 'string', 'min:3', 'max:64'], 'idempotency_key' => $this->idempotencyRules()]);
        $this->run(fn () => $this->stock->changeLotState($lot, $actor, $state, $data['reason_code'], $data['idempotency_key']));

        return back()->with('success', $message);
    }

    /** @return list<string> */
    private function idempotencyRules(): array
    {
        return ['required', 'string', 'min:8', 'max:128'];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    private function run(callable $operation): void
    {
        try {
            $operation();
        } catch (PharmacyDenied $denial) {
            throw ValidationException::withMessages(['pharmacy' => __($denial->getMessage())]);
        } catch (PharmacyAuditUnavailable $unavailable) {
            report($unavailable);
            abort(503, 'Pharmacy audit recording is unavailable.');
        }
    }
}
