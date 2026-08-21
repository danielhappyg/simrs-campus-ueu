<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\Patient;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientRegistrationController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::PATIENT_SEARCH);
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $search = trim((string) $request->query('q', ''));
        $searchResults = [];
        $todaysEncounters = [];

        try {
            if ($search !== '') {
                $searchResults = Patient::query()
                    ->where('is_synthetic', true)
                    ->where(function ($query) use ($search): void {
                        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                        $query->where('full_name', $like, '%'.$search.'%')
                            ->orWhere('medical_record_number', $like, '%'.$search.'%');
                    })
                    ->orderBy('full_name')
                    ->limit(20)
                    ->get()
                    ->map(fn (Patient $patient): array => [
                        'public_id' => $patient->public_id,
                        'medical_record_number' => $patient->medical_record_number,
                        'full_name' => $patient->full_name,
                        'date_of_birth' => $patient->date_of_birth?->toDateString(),
                        'sex' => $patient->sex,
                    ])
                    ->all();
            }

            $todaysEncounters = Encounter::query()
                ->with('patient')
                ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                ->whereDate('registered_at', today())
                ->orderByDesc('registered_at')
                ->limit(50)
                ->get()
                ->map(fn (Encounter $encounter): array => $this->encounterSummary($encounter))
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pendaftaran/rawat-jalan', [
            'q' => $search,
            'searchResults' => $searchResults,
            'todaysEncounters' => $todaysEncounters,
            'sexOptions' => [
                ['value' => Patient::SEX_LAKI_LAKI, 'label' => 'Laki-laki'],
                ['value' => Patient::SEX_PEREMPUAN, 'label' => 'Perempuan'],
                ['value' => Patient::SEX_TIDAK_DIKETAHUI, 'label' => 'Tidak diketahui'],
            ],
            'payerOptions' => [
                ['value' => Encounter::PAYER_UMUM, 'label' => 'Umum'],
                ['value' => Encounter::PAYER_BPJS, 'label' => 'BPJS'],
                ['value' => Encounter::PAYER_LAINNYA, 'label' => 'Lainnya'],
            ],
            'canRegister' => $request->user()?->canCapability(Capability::PATIENT_REGISTER) ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Capability::PATIENT_REGISTER);

        $validated = $request->validate([
            'patient_public_id' => ['nullable', 'string', 'exists:patients,public_id'],
            'full_name' => ['required_without:patient_public_id', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['required_without:patient_public_id', 'nullable', 'date'],
            'sex' => ['required_without:patient_public_id', 'nullable', Rule::in(Patient::SEX_VALUES)],
            'medical_record_number' => ['nullable', 'string', 'max:64', 'unique:patients,medical_record_number'],
            'clinic_name' => ['required', 'string', 'max:255'],
            'payer_type' => ['required', Rule::in(Encounter::PAYER_VALUES)],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'is_synthetic' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_synthetic', $validated) && $validated['is_synthetic'] === false) {
            abort(422, 'Hanya pasien sintetis yang diizinkan.');
        }

        $user = $request->user();
        assert($user !== null);

        $encounter = DB::transaction(function () use ($validated, $user): Encounter {
            if (! empty($validated['patient_public_id'])) {
                $patient = Patient::query()
                    ->where('public_id', $validated['patient_public_id'])
                    ->firstOrFail();

                if (! $patient->is_synthetic) {
                    abort(422, 'Hanya pasien sintetis yang diizinkan.');
                }
            } else {
                $mrn = $validated['medical_record_number'] ?? null;
                if (! is_string($mrn) || $mrn === '') {
                    $mrn = $this->generateMedicalRecordNumber();
                }

                $patient = Patient::query()->create([
                    'medical_record_number' => $mrn,
                    'full_name' => $validated['full_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'sex' => $validated['sex'],
                    'is_synthetic' => true,
                    'created_by_user_id' => $user->id,
                ]);
            }

            $created = Encounter::query()->create([
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_REGISTERED,
                'clinic_name' => $validated['clinic_name'],
                'payer_type' => $validated['payer_type'],
                'registered_at' => now(),
                'registered_by_user_id' => $user->id,
                'chief_complaint' => $validated['chief_complaint'] ?? null,
            ]);

            $created->setRelation('patient', $patient);

            return $created;
        });

        $this->auditRecorder->record(
            action: 'patient.register',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            outcome: 'SUCCESS',
            metadata: [
                'patient_public_id' => $encounter->patient?->public_id,
                'clinic_name' => $encounter->clinic_name,
                'payer_type' => $encounter->payer_type,
            ],
        );

        return redirect()
            ->route('pendaftaran.rawat-jalan.index')
            ->with('success', 'Pendaftaran rawat jalan berhasil.');
    }

    private function generateMedicalRecordNumber(): string
    {
        do {
            $mrn = 'RM-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (Patient::query()->where('medical_record_number', $mrn)->exists());

        return $mrn;
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
            'clinic_name' => $encounter->clinic_name,
            'payer_type' => $encounter->payer_type,
            'registered_at' => $encounter->registered_at?->toIso8601String(),
            'patient' => [
                'public_id' => $patient?->public_id,
                'medical_record_number' => $patient?->medical_record_number,
                'full_name' => $patient?->full_name,
            ],
        ];
    }
}
