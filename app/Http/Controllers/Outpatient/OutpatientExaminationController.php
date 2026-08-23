<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Support\Authorization\Capability;
use App\Support\Clinical\LabTestCatalog;
use App\Support\Clinical\OutpatientLabLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientExaminationController extends Controller
{
    public function __construct(private readonly OutpatientLabLifecycle $lifecycle) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $q = trim((string) $request->query('q', ''));
        $clinic = trim((string) $request->query('clinic', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $encounters = [];
        $clinics = [];

        try {
            $clinics = Clinic::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Clinic $row): array => [
                    'value' => $row->name,
                    'label' => $row->name,
                ])
                ->all();

            $query = Encounter::query()
                ->syntheticOnly()
                ->with('patient')
                ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                ->whereIn('status', Encounter::EXAMINATION_STATUSES);

            if ($clinic !== '') {
                $query->where('clinic_name', $clinic);
            }

            if ($dateFrom !== '') {
                $query->whereDate('registered_at', '>=', $dateFrom);
            }

            if ($dateTo !== '') {
                $query->whereDate('registered_at', '<=', $dateTo);
            }

            if ($q !== '') {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->whereHas('patient', function ($patientQuery) use ($q, $like): void {
                    $patientQuery->where('full_name', $like, '%'.$q.'%')
                        ->orWhere('medical_record_number', $like, '%'.$q.'%');
                });
            }

            $encounters = $query
                ->orderBy('registered_at')
                ->limit(100)
                ->get()
                ->map(fn (Encounter $encounter): array => [
                    'public_id' => $encounter->public_id,
                    'status' => $encounter->status,
                    'clinic_name' => $encounter->clinic_name,
                    'doctor_name' => $encounter->doctor_name,
                    'schedule_label' => $encounter->schedule_label,
                    'payer_type' => $encounter->payer_type,
                    'queue_number' => $encounter->queue_number,
                    'registered_at' => $encounter->registered_at->toIso8601String(),
                    'visit_date' => $encounter->visit_date?->toDateString(),
                    'chief_complaint' => $encounter->chief_complaint,
                    'patient' => [
                        'public_id' => $encounter->patient?->public_id,
                        'medical_record_number' => $encounter->patient?->medical_record_number,
                        'full_name' => $encounter->patient?->full_name,
                        'date_of_birth' => $encounter->patient?->date_of_birth->toDateString(),
                        'sex' => $encounter->patient?->sex,
                    ],
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pemeriksaan/rawat-jalan/index', [
            'encounters' => $encounters,
            'clinics' => $clinics,
            'filters' => [
                'q' => $q,
                'clinic' => $clinic,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'canOpen' => $request->user()?->canCapability(Capability::ENCOUNTER_OPEN) ?? false,
        ]);
    }

    public function show(Request $request, Encounter $encounter): Response
    {
        Gate::authorize(Capability::ENCOUNTER_OPEN);

        abort_unless(
            $encounter->care_setting === Encounter::CARE_SETTING_OUTPATIENT,
            404,
        );

        $encounter->load(['patient', 'clinicalEntries.author', 'labServiceRequests.requestedBy', 'labServiceRequests.result.enteredBy']);

        $user = $request->user();
        assert($user !== null);

        return Inertia::render('pemeriksaan/rawat-jalan/show', [
            'encounter' => [
                'public_id' => $encounter->public_id,
                'status' => $encounter->status,
                'clinic_name' => $encounter->clinic_name,
                'doctor_name' => $encounter->doctor_name,
                'schedule_label' => $encounter->schedule_label,
                'payer_type' => $encounter->payer_type,
                'queue_number' => $encounter->queue_number,
                'registered_at' => $encounter->registered_at->toIso8601String(),
                'visit_date' => $encounter->visit_date?->toDateString(),
                'chief_complaint' => $encounter->chief_complaint,
                'patient' => [
                    'public_id' => $encounter->patient?->public_id,
                    'medical_record_number' => $encounter->patient?->medical_record_number,
                    'full_name' => $encounter->patient?->full_name,
                    'date_of_birth' => $encounter->patient?->date_of_birth->toDateString(),
                    'sex' => $encounter->patient?->sex,
                    'nik' => $encounter->patient?->nik,
                ],
                'entries' => $encounter->clinicalEntries
                    ->sortBy('created_at')
                    ->values()
                    ->map(fn (ClinicalEntry $entry): array => [
                        'public_id' => $entry->public_id,
                        'entry_type' => $entry->entry_type,
                        'body' => $entry->body,
                        'created_at' => $entry->created_at?->toIso8601String(),
                        'author_name' => $entry->author?->name,
                    ])
                    ->all(),
                'lab_orders' => $encounter->labServiceRequests
                    ->sortByDesc('requested_at')
                    ->values()
                    ->map(fn (LabServiceRequest $order): array => [
                        'public_id' => $order->public_id,
                        'test_code' => $order->test_code,
                        'test_label' => $order->test_label,
                        'clinical_question' => $order->clinical_question,
                        'status' => $order->status,
                        'requested_at' => $order->requested_at->toIso8601String(),
                        'requested_by_name' => $order->requestedBy?->name,
                        'result' => $order->result ? [
                            'public_id' => $order->result->public_id,
                            'status' => $order->result->status,
                            'result_text' => $order->result->result_text,
                            'issued_at' => $order->result->issued_at->toIso8601String(),
                            'entered_by_name' => $order->result->enteredBy?->name,
                        ] : null,
                    ])
                    ->all(),
            ],
            'labTestOptions' => LabTestCatalog::all(),
            'canCreateLabOrder' => $user->canCapability(Capability::CLINICAL_ORDER_CREATE),
            'entryTypeOptions' => [
                [
                    'value' => ClinicalEntry::TYPE_NURSING_INTAKE,
                    'label' => 'Asesmen keperawatan',
                    'allowed' => $user->canCapability(Capability::CLINICAL_NURSING_WRITE),
                ],
                [
                    'value' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                    'label' => 'Asesmen medis',
                    'allowed' => $user->canCapability(Capability::CLINICAL_MEDICAL_WRITE),
                ],
            ],
            'canWriteNursing' => $user->canCapability(Capability::CLINICAL_NURSING_WRITE),
            'canWriteMedical' => $user->canCapability(Capability::CLINICAL_MEDICAL_WRITE),
        ]);
    }

    public function storeEntry(Request $request, Encounter $encounter): RedirectResponse
    {
        $validated = $request->validate([
            'entry_type' => ['required', Rule::in(ClinicalEntry::TYPE_VALUES)],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $capability = $validated['entry_type'] === ClinicalEntry::TYPE_NURSING_INTAKE
            ? Capability::CLINICAL_NURSING_WRITE
            : Capability::CLINICAL_MEDICAL_WRITE;

        Gate::authorize($capability);

        $user = $request->user();
        assert($user !== null);

        $this->lifecycle->writeClinicalEntry(
            encounter: $encounter,
            actor: $user,
            entryType: $validated['entry_type'],
            body: $validated['body'],
        );

        return redirect()
            ->route('pemeriksaan.rawat-jalan.show', $encounter)
            ->with('success', 'Catatan klinis disimpan.');
    }

    public function storeLabOrder(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::CLINICAL_ORDER_CREATE);

        $validated = $request->validate([
            'test_code' => ['required', Rule::in(LabTestCatalog::codes())],
            'clinical_question' => ['nullable', 'string', 'max:2000'],
        ]);

        $test = LabTestCatalog::find($validated['test_code']);
        assert($test !== null);

        $user = $request->user();
        assert($user !== null);

        $this->lifecycle->createLabOrder(
            encounter: $encounter,
            actor: $user,
            test: $test,
            clinicalQuestion: $validated['clinical_question'] ?? null,
        );

        return redirect()
            ->route('pemeriksaan.rawat-jalan.show', $encounter)
            ->with('success', 'Order lab disimpan.');
    }
}
