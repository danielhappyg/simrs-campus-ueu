<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\Patient;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Database\SchemaQualifier;
use App\Support\TeachingVocabulary;
use Database\Seeders\InpatientMastersSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InpatientRegistrationController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

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
        $wards = InpatientMastersSeeder::wardsCatalogue();

        try {
            if ($search !== '') {
                $searchResults = Patient::query()
                    ->where('is_synthetic', true)
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
                ->with('patient')
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
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pendaftaran/rawat-inap', [
            'q' => $search,
            'searchResults' => $searchResults,
            'todaysEncounters' => $todaysEncounters,
            'wards' => $wards,
            'wardOptions' => InpatientMastersSeeder::wardFilterOptions(),
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Capability::PATIENT_REGISTER);

        $validated = $request->validate([
            'patient_public_id' => ['nullable', 'string', Rule::exists(SchemaQualifier::table('patients'), 'public_id')],
            'full_name' => ['required_without:patient_public_id', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['required_without:patient_public_id', 'nullable', 'date'],
            'sex' => ['required_without:patient_public_id', 'nullable', Rule::in(Patient::SEX_VALUES)],
            'medical_record_number' => ['nullable', 'string', 'max:64', Rule::unique(SchemaQualifier::table('patients'), 'medical_record_number')],
            'nik' => ['nullable', 'string', 'max:16'],
            'phone' => ['nullable', 'string', 'max:32'],
            'ward_name' => ['required', 'string', 'max:120'],
            'ward_class' => ['required', 'string', 'max:120'],
            'bed_code' => ['required', 'string', 'max:32'],
            'payer_type' => ['required', Rule::in(Encounter::PAYER_VALUES)],
            'insurance_number' => ['nullable', 'string', 'max:64'],
            'continue_from' => ['required', Rule::in(Encounter::CONTINUE_FROM_VALUES)],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'is_synthetic' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_synthetic', $validated) && $validated['is_synthetic'] === false) {
            abort(422, 'Hanya pasien sintetis yang diizinkan.');
        }

        $this->assertValidWardSelection(
            $validated['ward_name'],
            $validated['ward_class'],
            $validated['bed_code'],
        );

        $bedTaken = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('bed_code', $validated['bed_code'])
            ->where('status', '!=', Encounter::STATUS_CLOSED)
            ->exists();

        if ($bedTaken) {
            return redirect()
                ->route('pendaftaran.rawat-inap.index')
                ->withErrors(['bed_code' => 'Tempat tidur sudah dipakai kunjungan rawat inap aktif.'])
                ->withInput();
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

                $patient->fill($this->patientUpdatableAttributes($validated))->save();
            } else {
                $mrn = $validated['medical_record_number'] ?? null;
                if (! is_string($mrn) || $mrn === '') {
                    $mrn = $this->generateMedicalRecordNumber();
                }

                $patient = Patient::query()->create([
                    ...$this->patientUpdatableAttributes($validated),
                    'medical_record_number' => $mrn,
                    'full_name' => $validated['full_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'sex' => $validated['sex'],
                    'is_synthetic' => true,
                    'created_by_user_id' => $user->id,
                ]);
            }

            $queueNumber = ((int) Encounter::query()
                ->whereDate('registered_at', today())
                ->max('queue_number')) + 1;

            $created = Encounter::query()->create([
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'status' => Encounter::STATUS_REGISTERED,
                'clinic_name' => $validated['ward_name'],
                'ward_name' => $validated['ward_name'],
                'ward_class' => $validated['ward_class'],
                'bed_code' => $validated['bed_code'],
                'continue_from' => $validated['continue_from'],
                'visit_date' => today(),
                'payer_type' => $validated['payer_type'],
                'insurance_number' => $validated['insurance_number'] ?? null,
                'queue_number' => $queueNumber,
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
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'patient_public_id' => $encounter->patient?->public_id,
                'ward_name' => $encounter->ward_name,
                'ward_class' => $encounter->ward_class,
                'bed_code' => $encounter->bed_code,
                'continue_from' => $encounter->continue_from,
                'payer_type' => $encounter->payer_type,
                'queue_number' => $encounter->queue_number,
            ],
        );

        return redirect()
            ->route('pendaftaran.rawat-inap.index')
            ->with('success', 'Pendaftaran rawat inap berhasil.')
            ->with('last_encounter_public_id', $encounter->public_id);
    }

    private function assertValidWardSelection(string $wardName, string $wardClass, string $bedCode): void
    {
        foreach (InpatientMastersSeeder::wardsCatalogue() as $ward) {
            if ($ward['name'] === $wardName && $ward['class'] === $wardClass && in_array($bedCode, $ward['beds'], true)) {
                return;
            }
        }

        abort(422, 'Kombinasi bangsal, kelas, dan tempat tidur tidak valid.');
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
            'patient' => [
                'public_id' => $patient?->public_id,
                'medical_record_number' => $patient?->medical_record_number,
                'full_name' => $patient?->full_name,
            ],
        ];
    }
}
