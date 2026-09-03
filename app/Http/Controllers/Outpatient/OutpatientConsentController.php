<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\EncounterConsentRecord;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Registration\EncounterConsent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientConsentController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function show(Request $request, Encounter $encounter): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $encounter->loadMissing(['patient', 'consent']);
        abort_unless($encounter->patient?->is_synthetic === true, 404);

        if ($encounter->isCancelled()) {
            abort(409, 'General Consent tidak dapat dibuka untuk kunjungan yang telah dibatalkan.');
        }

        $consent = $encounter->consent;

        return Inertia::render('pendaftaran/general-consent', [
            'encounter' => [
                'public_id' => $encounter->public_id,
                'clinic_name' => $encounter->clinic_name,
                'visit_date' => optional($encounter->visit_date)->toDateString()
                    ?? optional($encounter->registered_at)->toDateString(),
                'patient' => [
                    'full_name' => $encounter->patient?->full_name,
                    'medical_record_number' => $encounter->patient?->medical_record_number,
                    'address_line' => $encounter->patient?->address_line,
                    'phone' => $encounter->patient?->phone,
                    'responsible_party_name' => $encounter->patient?->responsible_party_name,
                ],
            ],
            'form' => [
                'title' => EncounterConsent::FORM_TITLE,
                'subtitle' => EncounterConsent::FORM_SUBTITLE,
                'hospital_name' => EncounterConsent::HOSPITAL_NAME,
                'hospital_address' => EncounterConsent::HOSPITAL_ADDRESS,
                'intro' => EncounterConsent::INTRO,
                'clauses' => EncounterConsent::clauses(),
            ],
            'signatures' => $consent === null ? null : [
                'explainer_name' => $consent->explainer_name,
                'patient_or_guardian_name' => $consent->patient_or_guardian_name,
                'explainer_signature_png' => $consent->explainer_signature_png,
                'patient_signature_png' => $consent->patient_signature_png,
                'signed_at' => optional($consent->signed_at)?->toIso8601String(),
            ],
            'printUrl' => route('pendaftaran.kunjungan.cetak', $encounter).'?docs=consent',
            'backUrl' => route('pendaftaran.rawat-jalan.index'),
        ]);
    }

    public function store(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::PATIENT_REGISTER);

        $encounter->loadMissing('patient');
        abort_unless($encounter->patient?->is_synthetic === true, 404);

        if ($encounter->isCancelled()) {
            abort(409, 'General Consent tidak dapat ditandatangani untuk kunjungan yang telah dibatalkan.');
        }

        $validated = $request->validate([
            'explainer_name' => ['required', 'string', 'max:255'],
            'patient_or_guardian_name' => ['required', 'string', 'max:255'],
            'explainer_signature_png' => ['required', 'string'],
            'patient_signature_png' => ['required', 'string'],
        ]);

        try {
            $explainerSignature = EncounterConsent::assertPngDataUrl($validated['explainer_signature_png']);
            $patientSignature = EncounterConsent::assertPngDataUrl($validated['patient_signature_png']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors([
                'explainer_signature_png' => $exception->getMessage(),
            ]);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        DB::transaction(function () use ($encounter, $validated, $explainerSignature, $patientSignature, $user): void {
            EncounterConsentRecord::query()->updateOrCreate(
                ['encounter_id' => $encounter->id],
                [
                    'explainer_name' => $validated['explainer_name'],
                    'patient_or_guardian_name' => $validated['patient_or_guardian_name'],
                    'explainer_signature_png' => $explainerSignature,
                    'patient_signature_png' => $patientSignature,
                    'signed_at' => now(),
                    'signed_by_user_id' => $user->id,
                ],
            );

            $event = $this->auditRecorder->record(
                action: 'encounter.consent.sign',
                resourceType: 'encounter',
                resourceId: $encounter->public_id,
                actor: $user,
                outcome: 'SUCCESS',
                metadata: [
                    'teaching_only' => true,
                    'certified_tte' => false,
                ],
            );

            abort_if($event === null, 503, 'Tanda tangan tidak dapat disimpan karena audit gagal direkam.');
        });

        return redirect()
            ->route('pendaftaran.kunjungan.consent.show', $encounter)
            ->with('success', 'General Consent tersimpan.');
    }
}
