<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Capability;
use Database\Seeders\InpatientMastersSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InpatientExaminationController extends Controller
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);

        $q = trim((string) $request->query('q', ''));
        $ward = trim((string) $request->query('ward', $request->query('clinic', '')));
        $payer = trim((string) $request->query('payer', ''));
        $continueFrom = trim((string) $request->query('continue_from', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $encounters = [];
        $wardOptions = InpatientMastersSeeder::wardFilterOptions();

        try {
            $query = Encounter::query()
                ->syntheticOnly()
                ->with('patient')
                ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                ->whereIn('status', Encounter::EXAMINATION_STATUSES);

            if ($ward !== '') {
                $query->where('ward_name', $ward);
            }

            if ($payer !== '') {
                $query->where('payer_type', $payer);
            }

            if ($continueFrom !== '') {
                $query->where('continue_from', $continueFrom);
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
                ->map(fn (Encounter $encounter): array => $this->encounterRow($encounter))
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('pemeriksaan/rawat-jalan/index', [
            'variant' => 'rawat-inap',
            'indexPath' => '/pemeriksaan/rawat-inap',
            'showPathPrefix' => '/pemeriksaan/rawat-inap',
            'encounters' => $encounters,
            'clinics' => $wardOptions,
            'payerOptions' => $this->options([
                Encounter::PAYER_UMUM => 'Umum',
                Encounter::PAYER_BPJS => 'BPJS',
                Encounter::PAYER_LAINNYA => 'Lainnya',
            ]),
            'continueFromOptions' => $this->options([
                Encounter::CONTINUE_LANGSUNG => 'Langsung',
                Encounter::CONTINUE_DARI_IGD => 'Dari IGD',
                Encounter::CONTINUE_DARI_RJ => 'Dari Rawat Jalan',
            ]),
            'filters' => [
                'q' => $q,
                'clinic' => $ward,
                'payer' => $payer,
                'continue_from' => $continueFrom,
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
            $encounter->care_setting === Encounter::CARE_SETTING_INPATIENT,
            404,
        );

        $encounter->load(['patient', 'clinicalEntries.author']);

        $user = $request->user();
        assert($user !== null);

        return Inertia::render('pemeriksaan/rawat-jalan/show', [
            'variant' => 'rawat-inap',
            'indexPath' => '/pemeriksaan/rawat-inap',
            'showPathPrefix' => '/pemeriksaan/rawat-inap',
            'storeEntryPath' => route('pemeriksaan.rawat-inap.entries.store', $encounter, false),
            'encounter' => $this->encounterDetail($encounter),
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
        abort_unless(
            $encounter->care_setting === Encounter::CARE_SETTING_INPATIENT,
            404,
        );

        abort_if(
            in_array($encounter->status, [Encounter::STATUS_CLOSED], true),
            422,
            'Kunjungan sudah ditutup.',
        );

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

        DB::transaction(function () use ($validated, $encounter, $user): void {
            ClinicalEntry::query()->create([
                'encounter_id' => $encounter->id,
                'author_user_id' => $user->id,
                'entry_type' => $validated['entry_type'],
                'body' => $validated['body'],
            ]);

            if ($validated['entry_type'] === ClinicalEntry::TYPE_MEDICAL_ASSESSMENT) {
                $encounter->update(['status' => Encounter::STATUS_READY_FOR_RM]);
            } elseif ($encounter->status === Encounter::STATUS_REGISTERED) {
                $encounter->update(['status' => Encounter::STATUS_IN_EXAMINATION]);
            }
        });

        $this->auditRecorder->record(
            action: 'clinical.note.write',
            resourceType: 'encounter',
            resourceId: $encounter->public_id,
            actor: $user,
            outcome: 'SUCCESS',
            metadata: [
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'entry_type' => $validated['entry_type'],
            ],
        );

        return redirect()
            ->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', 'Catatan klinis disimpan.');
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterRow(Encounter $encounter): array
    {
        return [
            'public_id' => $encounter->public_id,
            'status' => $encounter->status,
            'clinic_name' => $encounter->ward_name,
            'ward_name' => $encounter->ward_name,
            'ward_class' => $encounter->ward_class,
            'bed_code' => $encounter->bed_code,
            'continue_from' => $encounter->continue_from,
            'doctor_name' => null,
            'schedule_label' => $encounter->bed_code,
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterDetail(Encounter $encounter): array
    {
        return [
            'public_id' => $encounter->public_id,
            'status' => $encounter->status,
            'clinic_name' => $encounter->ward_name,
            'ward_name' => $encounter->ward_name,
            'ward_class' => $encounter->ward_class,
            'bed_code' => $encounter->bed_code,
            'continue_from' => $encounter->continue_from,
            'doctor_name' => null,
            'schedule_label' => $encounter->bed_code,
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
        ];
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    private function options(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
