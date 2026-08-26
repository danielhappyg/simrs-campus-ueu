<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\WilayahDistrict;
use App\Models\WilayahProvince;
use App\Models\WilayahRegency;
use App\Models\WilayahVillage;
use App\Services\Wilayah\WilayahRepository;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use App\Support\Database\SchemaAwareRules;
use App\Support\Http\InertiaPagination;
use App\Support\Registration\DailyQueueAllocator;
use App\Support\Registration\RegistrationFailureResponder;
use App\Support\TeachingVocabulary;
use Database\Seeders\OutpatientMastersSeeder;
use Database\Seeders\WilayahMinimalSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OutpatientRegistrationController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly WilayahRepository $wilayah,
        private readonly DailyQueueAllocator $dailyQueueAllocator,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize(Capability::PATIENT_SEARCH);
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $search = trim((string) $request->query('q', ''));
        $searchResults = [];
        $searchResultsTruncated = false;
        $todaysEncounters = [];
        $clinics = [];

        try {
            $this->ensureMastersSeeded();
            $todayStart = now()->startOfDay();
            $tomorrowStart = $todayStart->copy()->addDay();

            if ($search !== '') {
                $patientMatches = Patient::query()
                    ->syntheticOnly()
                    ->where(function ($query) use ($search): void {
                        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                        $query->where('full_name', $like, '%'.$search.'%')
                            ->orWhere('medical_record_number', $like, '%'.$search.'%')
                            ->orWhere('nik', $like, '%'.$search.'%');
                    })
                    ->orderBy('full_name')
                    ->limit(21)
                    ->get();
                $searchResultsTruncated = $patientMatches->count() > 20;
                $searchResults = $patientMatches
                    ->take(20)
                    ->map(fn (Patient $patient): array => $this->patientSummary($patient))
                    ->all();
            }

            $todaysEncounterPage = Encounter::query()
                ->syntheticOnly()
                ->with('patient')
                ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                ->where('registered_at', '>=', $todayStart)
                ->where('registered_at', '<', $tomorrowStart)
                ->orderByDesc('registered_at')
                ->orderByDesc('id')
                ->paginate(50, ['*'], 'encounter_page')
                ->withQueryString();
            if ($redirect = InertiaPagination::redirectIfOutOfRange($todaysEncounterPage, $request, 'encounter_page')) {
                return $redirect;
            }
            $todaysEncounters = collect($todaysEncounterPage->items())
                ->map(fn (Encounter $encounter): array => $this->encounterSummary($encounter))
                ->all();

            $clinics = Clinic::query()
                ->where('is_active', true)
                ->with([
                    'doctors' => fn ($query) => $query->where('is_active', true)->orderBy('name'),
                    'doctors.schedules' => fn ($query) => $query->where('is_active', true)->orderBy('label'),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (Clinic $clinic): array => [
                    'public_id' => $clinic->public_id,
                    'code' => $clinic->code,
                    'name' => $clinic->name,
                    'doctors' => $clinic->doctors->map(fn (Doctor $doctor): array => [
                        'public_id' => $doctor->public_id,
                        'name' => $doctor->name,
                        'specialty' => $doctor->specialty,
                        'schedules' => $doctor->schedules->map(fn (ClinicSchedule $schedule): array => [
                            'public_id' => $schedule->public_id,
                            'label' => $schedule->label,
                            'day_label' => $schedule->day_label,
                        ])->values()->all(),
                    ])->values()->all(),
                ])
                ->all();
        } catch (Throwable $e) {
            report($e);
        }

        return Inertia::render('pendaftaran/rawat-jalan', [
            'q' => $search,
            'searchResults' => $searchResults,
            'searchResultsTruncated' => $searchResultsTruncated,
            'todaysEncounters' => $todaysEncounters,
            'todaysEncountersPagination' => isset($todaysEncounterPage)
                ? InertiaPagination::from($todaysEncounterPage)
                : null,
            'clinics' => $clinics,
            'sexOptions' => TeachingVocabulary::options(TeachingVocabulary::SEX),
            'maritalOptions' => TeachingVocabulary::options(TeachingVocabulary::MARITAL),
            'religionOptions' => TeachingVocabulary::options(TeachingVocabulary::RELIGION),
            'educationOptions' => TeachingVocabulary::options(TeachingVocabulary::EDUCATION),
            'occupationOptions' => TeachingVocabulary::options(TeachingVocabulary::OCCUPATION),
            'ethnicityOptions' => TeachingVocabulary::options(TeachingVocabulary::ETHNICITY),
            'languageOptions' => TeachingVocabulary::options(TeachingVocabulary::LANGUAGE),
            'payerOptions' => TeachingVocabulary::options(TeachingVocabulary::PAYER),
            'admissionOptions' => TeachingVocabulary::options(TeachingVocabulary::ADMISSION),
            'wilayahProvinces' => $this->wilayah->provinces(),
            'canRegister' => $request->user()?->canCapability(Capability::PATIENT_REGISTER) ?? false,
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
        $this->ensureMastersSeeded();

        $validated = $request->validate([
            'patient_public_id' => ['nullable', 'string', SchemaAwareRules::exists(Patient::class, 'public_id')],
            'full_name' => ['required_without:patient_public_id', 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['required_without:patient_public_id', 'nullable', 'date'],
            'sex' => ['required_without:patient_public_id', 'nullable', Rule::in(Patient::SEX_VALUES)],
            'medical_record_number' => ['nullable', 'string', 'max:64', SchemaAwareRules::unique(Patient::class, 'medical_record_number')],
            'nik' => ['nullable', 'string', 'max:16'],
            'place_of_birth' => ['nullable', 'string', 'max:120'],
            'religion' => ['nullable', Rule::in(Patient::RELIGION_VALUES)],
            'marital_status' => ['nullable', Rule::in(Patient::MARITAL_VALUES)],
            'education' => ['nullable', Rule::in(Patient::EDUCATION_VALUES)],
            'occupation' => ['nullable', Rule::in(Patient::OCCUPATION_VALUES)],
            'province_code' => ['nullable', 'string', 'max:16', SchemaAwareRules::exists(WilayahProvince::class, 'code')],
            'city_code' => ['nullable', 'string', 'max:16', SchemaAwareRules::exists(WilayahRegency::class, 'code')],
            'district_code' => ['nullable', 'string', 'max:16', SchemaAwareRules::exists(WilayahDistrict::class, 'code')],
            'village_code' => ['nullable', 'string', 'max:16', SchemaAwareRules::exists(WilayahVillage::class, 'code')],
            'province' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'village' => ['nullable', 'string', 'max:120'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'domicile' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'ethnicity' => ['nullable', Rule::in(Patient::ETHNICITY_VALUES)],
            'language' => ['nullable', Rule::in(Patient::LANGUAGE_VALUES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'responsible_party_name' => ['nullable', 'string', 'max:255'],
            'clinic_public_id' => ['required', 'string', SchemaAwareRules::exists(Clinic::class, 'public_id')],
            'doctor_public_id' => ['required', 'string', SchemaAwareRules::exists(Doctor::class, 'public_id')],
            'schedule_public_id' => ['required', 'string', SchemaAwareRules::exists(ClinicSchedule::class, 'public_id')],
            'visit_date' => ['required', 'date'],
            'admission_mode' => ['required', Rule::in(Encounter::ADMISSION_VALUES)],
            'payer_type' => ['required', Rule::in(Encounter::PAYER_VALUES)],
            'insurance_number' => ['nullable', 'string', 'max:64'],
            'booking_code' => ['nullable', 'string', 'max:64'],
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'is_synthetic' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_synthetic', $validated) && $validated['is_synthetic'] === false) {
            abort(422, 'Hanya pasien sintetis yang diizinkan.');
        }

        $clinic = Clinic::query()
            ->where('public_id', $validated['clinic_public_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $doctor = Doctor::query()
            ->where('public_id', $validated['doctor_public_id'])
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->firstOrFail();

        $schedule = ClinicSchedule::query()
            ->where('public_id', $validated['schedule_public_id'])
            ->where('clinic_id', $clinic->id)
            ->where('doctor_id', $doctor->id)
            ->where('is_active', true)
            ->firstOrFail();

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $encounter = DB::transaction(function () use ($validated, $user, $clinic, $doctor, $schedule): Encounter {
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

            $registeredAt = now((string) config('app.timezone', 'Asia/Jakarta'));
            $queue = $this->dailyQueueAllocator->allocate($registeredAt);

            $created = Encounter::query()->create([
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => Encounter::STATUS_REGISTERED,
                'clinic_name' => $clinic->name,
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'clinic_schedule_id' => $schedule->id,
                'doctor_name' => $doctor->name,
                'schedule_label' => $schedule->label,
                'visit_date' => $validated['visit_date'],
                'admission_mode' => $validated['admission_mode'],
                'payer_type' => $validated['payer_type'],
                'insurance_number' => $validated['insurance_number'] ?? null,
                'booking_code' => $validated['booking_code'] ?? null,
                'queue_date' => $queue->queueDate,
                'queue_number' => $queue->queueNumber,
                'registered_at' => $registeredAt,
                'registered_by_user_id' => $user->id,
                'chief_complaint' => $validated['chief_complaint'] ?? null,
            ]);

            $created->setRelation('patient', $patient);

            $event = $this->auditRecorder->record(
                action: 'patient.register',
                resourceType: 'encounter',
                resourceId: $created->public_id,
                actor: $user,
                outcome: 'SUCCESS',
                metadata: [
                    'patient_public_id' => $created->patient?->public_id,
                    'clinic_name' => $created->clinic_name,
                    'doctor_name' => $created->doctor_name,
                    'schedule_label' => $created->schedule_label,
                    'payer_type' => $created->payer_type,
                    'queue_date' => $created->queue_date,
                    'queue_number' => $created->queue_number,
                ],
            );

            abort_if($event === null, 503, 'Aksi tidak dapat diselesaikan karena audit gagal direkam.');

            return $created;
        }, 3);

        return redirect()
            ->route('pendaftaran.rawat-jalan.index')
            ->with('success', 'Pendaftaran rawat jalan berhasil.')
            ->with('last_encounter_public_id', $encounter->public_id);
    }

    private function ensureMastersSeeded(): void
    {
        try {
            if (! WilayahProvince::query()->exists()) {
                (new WilayahMinimalSeeder)->run();
            }

            if (! Clinic::query()->exists()) {
                (new OutpatientMastersSeeder)->run();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function patientUpdatableAttributes(array $validated): array
    {
        $wilayah = $this->wilayah->resolvePatientWilayah([
            'province_code' => $validated['province_code'] ?? null,
            'city_code' => $validated['city_code'] ?? null,
            'district_code' => $validated['district_code'] ?? null,
            'village_code' => $validated['village_code'] ?? null,
        ]);

        return [
            'nik' => $validated['nik'] ?? null,
            'place_of_birth' => $validated['place_of_birth'] ?? null,
            'religion' => $validated['religion'] ?? null,
            'marital_status' => $validated['marital_status'] ?? null,
            'education' => $validated['education'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            ...$wilayah,
            'address_line' => $validated['address_line'] ?? null,
            'domicile' => $validated['domicile'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'ethnicity' => $validated['ethnicity'] ?? null,
            'language' => $validated['language'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'responsible_party_name' => $validated['responsible_party_name'] ?? null,
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
            'place_of_birth' => $patient->place_of_birth,
            'date_of_birth' => $patient->date_of_birth->toDateString(),
            'sex' => $patient->sex,
            'religion' => $patient->religion,
            'marital_status' => $patient->marital_status,
            'education' => $patient->education,
            'occupation' => $patient->occupation,
            'province_code' => $patient->province_code,
            'province' => $patient->province,
            'city_code' => $patient->city_code,
            'city' => $patient->city,
            'district_code' => $patient->district_code,
            'district' => $patient->district,
            'village_code' => $patient->village_code,
            'village' => $patient->village,
            'address_line' => $patient->address_line,
            'domicile' => $patient->domicile,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'ethnicity' => $patient->ethnicity,
            'language' => $patient->language,
            'notes' => $patient->notes,
            'responsible_party_name' => $patient->responsible_party_name,
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
            'clinic_name' => $encounter->clinic_name,
            'doctor_name' => $encounter->doctor_name,
            'schedule_label' => $encounter->schedule_label,
            'payer_type' => $encounter->payer_type,
            'booking_code' => $encounter->booking_code,
            'admission_mode' => $encounter->admission_mode,
            'queue_number' => $encounter->queue_number,
            'registered_at' => $encounter->registered_at->toIso8601String(),
            'patient' => [
                'public_id' => $patient?->public_id,
                'medical_record_number' => $patient?->medical_record_number,
                'full_name' => $patient?->full_name,
            ],
        ];
    }
}
