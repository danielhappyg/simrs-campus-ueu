<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\EmergencyDisposition;
use App\Models\EmergencyInpatientHandoff;
use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\OutpatientDisposition;
use App\Models\OutpatientInpatientHandoff;
use App\Models\Patient;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Database\SchemaAwareRules;
use App\Support\Inpatient\InpatientAdmissionDenied;
use App\Support\Inpatient\InpatientAdmissionService;
use App\Support\Inpatient\InpatientMasterDenied;
use App\Support\Inpatient\InpatientWardReadModel;
use App\Support\Registration\InpatientBedUnavailable;
use App\Support\Registration\MedicalRecordNumber;
use App\Support\Registration\MedicalRecordNumberAllocator;
use App\Support\Registration\RegistrationFailureResponder;
use App\Support\TeachingVocabulary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InpatientRegistrationController extends Controller
{
    public function __construct(
        private readonly InpatientAdmissionService $admissionService,
        private readonly InpatientWardReadModel $wardReadModel,
        private readonly MedicalRecordNumberAllocator $medicalRecordNumberAllocator,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::PATIENT_SEARCH);
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $search = trim((string) $request->query('q', ''));
        $ward = trim((string) $request->query('ward', ''));
        $payer = trim((string) $request->query('payer', ''));
        $continueFrom = trim((string) $request->query('continue_from', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $searchResults = [];
        $todaysEncounters = [];
        $pendingEmergencyAdmissions = [];
        $pendingOutpatientAdmissions = [];
        $wards = $this->wardReadModel->registrationCatalogue();

        try {
            if ($search !== '') {
                $searchResults = Patient::query()
                    ->syntheticOnly()
                    ->where(function ($query) use ($search): void {
                        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                        $query->where('full_name', $like, '%'.$search.'%')
                            ->orWhere('medical_record_number', $like, '%'.$search.'%')
                            ->orWhere('nik', $like, '%'.$search.'%');
                    })
                    ->orderBy('full_name')
                    ->limit(20)
                    ->get()
                    ->map(fn (Patient $patient): array => $this->patientSummary($patient))
                    ->all();
            }

            $encounterQuery = Encounter::query()
                ->syntheticOnly()
                ->with(['patient', 'cancellation.cancelledBy'])
                ->where('care_setting', Encounter::CARE_SETTING_INPATIENT);

            if ($ward !== '') {
                $encounterQuery->where('ward_name', $ward);
            }

            if ($payer !== '') {
                $encounterQuery->where('payer_type', $payer);
            }

            if ($continueFrom !== '') {
                $encounterQuery->where('continue_from', $continueFrom);
            }

            if ($dateFrom !== '') {
                $encounterQuery->whereDate('registered_at', '>=', $dateFrom);
            } elseif ($dateTo === '' && $ward === '' && $payer === '' && $continueFrom === '') {
                $encounterQuery->whereDate('registered_at', today());
            }

            if ($dateTo !== '') {
                $encounterQuery->whereDate('registered_at', '<=', $dateTo);
            }

            if ($search !== '' && count($searchResults) === 0) {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $encounterQuery->whereHas('patient', function ($patientQuery) use ($search, $like): void {
                    $patientQuery->where('full_name', $like, '%'.$search.'%')
                        ->orWhere('medical_record_number', $like, '%'.$search.'%');
                });
            }

            $todaysEncounters = $encounterQuery
                ->orderByDesc('registered_at')
                ->limit(50)
                ->get()
                ->map(fn (Encounter $encounter): array => $this->encounterSummary($encounter))
                ->all();

            $actor = $request->user();
            if ($actor instanceof User && $actor->canCapability(Capability::EMERGENCY_INPATIENT_HANDOFF)) {
                $pendingEmergencyAdmissions = $this->pendingEmergencyAdmissions();
                $pendingOutpatientAdmissions = $this->pendingOutpatientAdmissions();
            }
        } catch (Throwable $e) {
            report($e);
        }

        return Inertia::render('pendaftaran/rawat-inap', [
            'q' => $search,
            'searchResults' => $searchResults,
            'todaysEncounters' => $todaysEncounters,
            'pendingEmergencyAdmissions' => $pendingEmergencyAdmissions,
            'pendingOutpatientAdmissions' => $pendingOutpatientAdmissions,
            'wards' => $wards,
            'wardOptions' => $this->wardReadModel->filterOptions(),
            'sexOptions' => TeachingVocabulary::options(TeachingVocabulary::SEX),
            'payerOptions' => TeachingVocabulary::options(TeachingVocabulary::PAYER),
            'continueFromOptions' => TeachingVocabulary::options(TeachingVocabulary::CONTINUE_FROM),
            'filters' => [
                'q' => $search,
                'ward' => $ward,
                'payer' => $payer,
                'continue_from' => $continueFrom,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'canRegister' => $request->user()?->canCapability(Capability::PATIENT_REGISTER) ?? false,
            'canOpen' => $request->user()?->canCapability(Capability::ENCOUNTER_OPEN) ?? false,
            'canCancel' => $request->user()?->canCapability(Capability::ENCOUNTER_CANCEL) ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Capability::PATIENT_REGISTER);

        try {
            return $this->storeRegistration($request);
        } catch (Throwable $exception) {
            $redirect = RegistrationFailureResponder::redirect($request, $exception);

            if ($redirect !== null) {
                return $redirect;
            }

            throw $exception;
        }
    }

    private function storeRegistration(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_public_id' => ['nullable', 'string', SchemaAwareRules::exists(Patient::class, 'public_id')],
            'full_name' => ['required_without:patient_public_id', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['required_without:patient_public_id', 'nullable', 'date'],
            'sex' => ['required_without:patient_public_id', 'nullable', Rule::in(Patient::SEX_VALUES)],
            'medical_record_number' => ['nullable', 'string', 'regex:/^[0-9]{6}$/', SchemaAwareRules::unique(Patient::class, 'medical_record_number')],
            'nik' => ['nullable', 'string', 'size:16', 'regex:/\A[0-9]{16}\z/'],
            'phone' => ['nullable', 'string', 'max:32'],
            'bed_public_id' => ['required', 'string', 'size:26', 'regex:/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', SchemaAwareRules::exists(InpatientBed::class, 'public_id')],
            'ward_name' => ['nullable', 'string', 'max:120'],
            'ward_class' => ['nullable', 'string', 'max:120'],
            'bed_code' => ['nullable', 'string', 'max:64'],
            'payer_type' => ['required', Rule::in(Encounter::PAYER_VALUES)],
            'insurance_number' => ['nullable', 'string', 'max:64'],
            'continue_from' => ['required', Rule::in([Encounter::CONTINUE_LANGSUNG])],
            'admission_authority_type' => ['required', Rule::in(Encounter::DIRECT_ADMISSION_AUTHORITY_VALUES)],
            'admission_authority_reference' => ['required', 'string', 'min:3', 'max:255'],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'is_synthetic' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_synthetic', $validated) && $validated['is_synthetic'] === false) {
            abort(422, 'Hanya pasien sintetis yang diizinkan.');
        }

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $encounter = DB::transaction(function () use ($validated, $user): Encounter {
                if (! empty($validated['patient_public_id'])) {
                    $patient = Patient::query()
                        ->syntheticOnly()
                        ->where('public_id', $validated['patient_public_id'])
                        ->firstOrFail();

                    if (! $patient->is_synthetic) {
                        abort(422, 'Hanya pasien sintetis yang diizinkan.');
                    }

                    $patient->fill($this->patientUpdatableAttributes($validated))->save();
                } else {
                    $providedMrn = $validated['medical_record_number'] ?? null;
                    if (! is_string($providedMrn) || $providedMrn === '') {
                        $mrn = $this->medicalRecordNumberAllocator->allocate();
                    } else {
                        $mrn = new MedicalRecordNumber($providedMrn);
                    }

                    $patient = Patient::query()->create([
                        ...$this->patientUpdatableAttributes($validated),
                        'medical_record_number' => $mrn->value,
                        'full_name' => mb_strtoupper(trim((string) $validated['full_name']), 'UTF-8'),
                        'date_of_birth' => $validated['date_of_birth'],
                        'sex' => $validated['sex'],
                        'is_synthetic' => true,
                        'created_by_user_id' => $user->id,
                    ]);

                    if (is_string($providedMrn) && $providedMrn !== '') {
                        $this->medicalRecordNumberAllocator->ensureHighWatermark((int) $providedMrn);
                    }
                }

                return $this->admissionService->admitDirect(
                    patient: $patient,
                    actor: $user,
                    bedPublicId: $validated['bed_public_id'],
                    payerType: $validated['payer_type'],
                    insuranceNumber: $validated['insurance_number'] ?? null,
                    continueFrom: $validated['continue_from'],
                    chiefComplaint: $validated['chief_complaint'] ?? null,
                    requestCorrelationId: request()->attributes->get('request_id'),
                    admissionAuthorityType: $validated['admission_authority_type'],
                    admissionAuthorityReference: $validated['admission_authority_reference'],
                )->encounter;
            }, 3);
        } catch (InpatientBedUnavailable $exception) {
            return redirect()
                ->route('pendaftaran.rawat-inap.index')
                ->withErrors(['bed_code' => $exception->getMessage()])
                ->withInput();
        } catch (InpatientMasterDenied $exception) {
            return redirect()
                ->route('pendaftaran.rawat-inap.index')
                ->withErrors(['bed_public_id' => $exception->getMessage()])
                ->withInput();
        } catch (InpatientAdmissionDenied $exception) {
            if ($exception->httpStatus >= 500) {
                abort($exception->httpStatus, $exception->getMessage());
            }

            return redirect()
                ->route('pendaftaran.rawat-inap.index')
                ->withErrors(['patient_public_id' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('pendaftaran.rawat-inap.index')
            ->with('success', 'Pendaftaran rawat inap berhasil.')
            ->with('last_encounter_public_id', $encounter->public_id);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function patientUpdatableAttributes(array $validated): array
    {
        return [
            'nik' => $validated['nik'] ?? null,
            'phone' => $validated['phone'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingEmergencyAdmissions(): array
    {
        $dispositions = (new EmergencyDisposition)->getTable();
        $handoffs = (new EmergencyInpatientHandoff)->getTable();

        $admissions = EmergencyDisposition::query()
            ->with('encounter.patient')
            ->where('disposition_type', 'RAWAT_INAP')
            ->where('version', function ($query) use ($dispositions): void {
                $query->selectRaw('max(latest_disposition.version)')
                    ->from($dispositions.' as latest_disposition')
                    ->whereColumn('latest_disposition.encounter_id', $dispositions.'.encounter_id');
            })
            ->whereHas('encounter', fn ($query) => $query
                ->whereHas('patient', fn ($patientQuery) => $patientQuery->where('is_synthetic', true))
                ->where('care_setting', Encounter::CARE_SETTING_EMERGENCY)
                ->where('status', Encounter::STATUS_IN_EXAMINATION))
            ->whereNotExists(function ($query) use ($handoffs, $dispositions): void {
                $query->selectRaw('1')
                    ->from($handoffs.' as completed_handoff')
                    ->whereColumn('completed_handoff.source_encounter_id', $dispositions.'.encounter_id');
            })
            ->orderByDesc('signed_at')
            ->limit(50)
            ->get()
            ->map(function (EmergencyDisposition $disposition): array {
                $encounter = $disposition->encounter;
                $patient = $encounter->patient;
                $admissionReason = $disposition->payload['admission_reason'] ?? null;

                return [
                    'source_encounter_public_id' => $encounter->public_id,
                    'disposition_public_id' => $disposition->public_id,
                    'disposition_version' => $disposition->version,
                    'signed_at' => $disposition->signed_at->toIso8601String(),
                    'payer_type' => $encounter->payer_type,
                    'admission_reason' => is_string($admissionReason) ? $admissionReason : null,
                    'patient' => [
                        'medical_record_number' => $patient?->medical_record_number,
                        'full_name' => $patient?->full_name,
                    ],
                    'handoff_url' => route('pemeriksaan.igd.show', $encounter).'?tab=disposition',
                ];
            })
            ->values()
            ->all();

        return array_values($admissions);
    }

    /** @return list<array<string,mixed>> */
    private function pendingOutpatientAdmissions(): array
    {
        $dispositions = (new OutpatientDisposition)->getTable();
        $handoffs = (new OutpatientInpatientHandoff)->getTable();

        $items = OutpatientDisposition::query()->with('encounter.patient')
            ->where('disposition_type', 'RAWAT_INAP')
            ->where('version', fn ($query) => $query->selectRaw('max(latest.version)')->from($dispositions.' as latest')->whereColumn('latest.encounter_id', $dispositions.'.encounter_id'))
            ->whereHas('encounter', fn (Builder $query) => $query->whereHas('patient', fn (Builder $patientQuery) => $patientQuery->where('is_synthetic', true))->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)->where('status', Encounter::STATUS_IN_EXAMINATION))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from($handoffs.' as completed')->whereColumn('completed.source_encounter_id', $dispositions.'.encounter_id'))
            ->orderByDesc('signed_at')->limit(50)->get()->map(function (OutpatientDisposition $disposition): array {
                $encounter = $disposition->encounter;
                $patient = $encounter->patient;

                return ['source_encounter_public_id' => $encounter->public_id, 'disposition_public_id' => $disposition->public_id, 'disposition_version' => $disposition->version, 'signed_at' => $disposition->signed_at->toIso8601String(), 'payer_type' => $encounter->payer_type, 'admission_reason' => $disposition->payload['admission_reason'] ?? null, 'patient' => ['medical_record_number' => $patient?->medical_record_number, 'full_name' => $patient?->full_name], 'handoff_url' => route('outpatient.disposition.handoff', $encounter), 'source_url' => route('pemeriksaan.rawat-jalan.show', $encounter).'?tab=disposition'];
            })->values()->all();

        return array_values($items);
    }

    /**
     * @return array<string, mixed>
     */
    private function patientSummary(Patient $patient): array
    {
        return [
            'public_id' => $patient->public_id,
            'medical_record_number' => $patient->medical_record_number,
            'nik' => $patient->nik,
            'full_name' => $patient->full_name,
            'date_of_birth' => $patient->date_of_birth->toDateString(),
            'sex' => $patient->sex,
            'phone' => $patient->phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterSummary(Encounter $encounter): array
    {
        $patient = $encounter->patient;

        return [
            'public_id' => $encounter->public_id,
            'status' => $encounter->status,
            'ward_name' => $encounter->ward_name,
            'ward_class' => $encounter->ward_class,
            'bed_code' => $encounter->bed_code,
            'continue_from' => $encounter->continue_from,
            'payer_type' => $encounter->payer_type,
            'queue_number' => $encounter->queue_number,
            'registered_at' => $encounter->registered_at->toIso8601String(),
            'chief_complaint' => $encounter->chief_complaint,
            'cancellation' => $this->cancellationSummary($encounter),
            'patient' => [
                'public_id' => $patient?->public_id,
                'medical_record_number' => $patient?->medical_record_number,
                'full_name' => $patient?->full_name,
            ],
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function cancellationSummary(Encounter $encounter): ?array
    {
        if (! $encounter->relationLoaded('cancellation')) {
            return null;
        }

        $cancellation = $encounter->getRelation('cancellation');
        if ($cancellation === null) {
            return null;
        }

        $cancelledAt = $cancellation->getAttribute('cancelled_at');
        $cancelledBy = $cancellation->relationLoaded('cancelledBy')
            ? $cancellation->getRelation('cancelledBy')
            : null;

        return [
            'reason_code' => $cancellation->getAttribute('reason_code'),
            'note' => $cancellation->getAttribute('note'),
            'cancelled_at' => $cancelledAt instanceof \DateTimeInterface
                ? $cancelledAt->format(DATE_ATOM)
                : (is_string($cancelledAt) ? $cancelledAt : null),
            'cancelled_by' => $cancelledBy?->getAttribute('name'),
        ];
    }
}
