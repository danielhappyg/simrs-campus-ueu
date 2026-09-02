<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\OutpatientClinicalDocument;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Clinical\LegacyLaboratoryCompatibilityProjection;
use App\Support\Clinical\OutpatientAmendmentProjection;
use App\Support\Clinical\OutpatientDocumentationDefinition;
use App\Support\Clinical\OutpatientDocumentationService;
use App\Support\Http\InertiaPagination;
use App\Support\Laboratory\LaboratoryProjection;
use App\Support\Pharmacy\PharmacyProjection;
use App\Support\Radiology\RadiologyProjection;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientExaminationController extends Controller
{
    public function __construct(
        private readonly OutpatientDocumentationService $documentationService,
        private readonly OutpatientAmendmentProjection $amendmentProjection,
        private readonly RadiologyProjection $radiologyProjection,
        private readonly LaboratoryProjection $laboratoryProjection,
        private readonly PharmacyProjection $pharmacyProjection,
        private readonly LegacyLaboratoryCompatibilityProjection $legacyLaboratoryProjection,
    ) {}

    public function index(Request $request): Response|RedirectResponse
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
                $query->where(
                    'registered_at',
                    '>=',
                    CarbonImmutable::parse($dateFrom, config('app.timezone'))->startOfDay(),
                );
            }

            if ($dateTo !== '') {
                $query->where(
                    'registered_at',
                    '<',
                    CarbonImmutable::parse($dateTo, config('app.timezone'))->startOfDay()->addDay(),
                );
            }

            if ($q !== '') {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $query->whereHas('patient', function ($patientQuery) use ($q, $like): void {
                    $patientQuery->where('full_name', $like, '%'.$q.'%')
                        ->orWhere('medical_record_number', $like, '%'.$q.'%');
                });
            }

            $encounterPage = $query
                ->orderBy('registered_at')
                ->orderBy('id')
                ->paginate(100)
                ->withQueryString();
            if ($redirect = InertiaPagination::redirectIfOutOfRange($encounterPage, $request)) {
                return $redirect;
            }
            $encounters = collect($encounterPage->items())
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
            'pagination' => isset($encounterPage)
                ? InertiaPagination::from($encounterPage)
                : null,
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

        $encounter->load([
            'patient',
            'clinicalEntries.author',
            'outpatientClinicalDocuments.author',
            'outpatientClinicalDocuments.finalizedBy',
            'outpatientClinicalDocuments.versions.actor',
            'outpatientRmCompletenessReviews',
            'outpatientPostClosureAmendmentRequests.requester',
            'outpatientPostClosureAmendmentRequests.decidedBy',
            'outpatientPostClosureAmendmentRequests.originalDocument',
            'outpatientPostClosureAmendmentRequests.addendum.author',
            'outpatientPostClosureAmendmentRequests.addendum.finalizedBy',
            'outpatientPostClosureAmendmentRequests.renewedReviews.items',
            'outpatientPostClosureAmendmentRequests.renewedReviews.reviewedBy',
            'outpatientPostClosureAmendmentRequests.renewedReviews.signedOffBy',
        ]);

        $user = $request->user();
        assert($user !== null);
        $canMutate = $encounter->isActive();

        return Inertia::render('pemeriksaan/rawat-jalan/show', [
            'variant' => 'rawat-jalan',
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
            ],
            'legacyEntries' => $encounter->clinicalEntries
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
            'documentation' => [
                'definition_version' => OutpatientClinicalDocument::DEFINITION_VERSION,
                'source_fingerprint' => $this->documentationFingerprint($encounter->outpatientClinicalDocuments->all()),
                'documents' => $encounter->outpatientClinicalDocuments
                    ->sortBy('document_type')
                    ->values()
                    ->map(fn (OutpatientClinicalDocument $document): array => $this->documentProjection($document))
                    ->all(),
                'active_drafts' => $encounter->outpatientClinicalDocuments
                    ->where('document_state', OutpatientClinicalDocument::STATE_DRAFT)
                    ->sortBy('document_type')
                    ->values()
                    ->map(fn (OutpatientClinicalDocument $document): array => $this->documentProjection($document))
                    ->all(),
                'versions' => $this->documentVersionProjections($encounter->outpatientClinicalDocuments),
            ],
            'permissions' => [
                ...$this->amendmentProjection->topPermissions($encounter, $user),
                'nursing' => [
                    'can_save_draft' => $canMutate && $user->canCapability(Capability::CLINICAL_NURSING_WRITE),
                    'can_finalize' => $canMutate && $user->canCapability(Capability::CLINICAL_NURSING_WRITE),
                ],
                'medical' => [
                    'can_save_draft' => $canMutate && $user->canCapability(Capability::CLINICAL_MEDICAL_WRITE),
                    'can_finalize' => $canMutate && $user->canCapability(Capability::CLINICAL_MEDICAL_WRITE),
                ],
                'can_create_lab_order' => false,
            ],
            'actions' => [
                ...$this->amendmentProjection->topActions($encounter, $user),
                'nursing' => [
                    'save_draft_url' => route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]),
                    'finalize_url' => route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT]),
                ],
                'medical' => [
                    'save_draft_url' => route('pemeriksaan.rawat-jalan.documents.draft', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]),
                    'finalize_url' => route('pemeriksaan.rawat-jalan.documents.final', [$encounter, OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT]),
                ],
            ],
            'amendmentReasonOptions' => $this->amendmentProjection->reasonOptions(),
            'amendments' => $this->amendmentProjection->amendments($encounter, $user),
            'labTestOptions' => [],
            'radiology' => $this->radiologyProjection->encounter($encounter, $user),
            'laboratory' => $this->laboratoryEncounter($encounter, $user),
            'pharmacy' => $this->pharmacyProjection->encounter($encounter, $user),
        ]);
    }

    /** @return array<string, mixed> */
    private function laboratoryEncounter(Encounter $encounter, User $actor): array
    {
        $projection = $this->laboratoryProjection->encounter($encounter, $actor);
        $projection['orders'] = [
            ...$projection['orders'],
            ...$this->legacyLaboratoryProjection->encounter($encounter, $actor),
        ];

        return $projection;
    }

    public function saveDraft(Request $request, Encounter $encounter, string $documentType): RedirectResponse
    {
        return $this->saveDocument($request, $encounter, $documentType, false);
    }

    public function finalize(Request $request, Encounter $encounter, string $documentType): RedirectResponse
    {
        return $this->saveDocument($request, $encounter, $documentType, true);
    }

    private function saveDocument(Request $request, Encounter $encounter, string $documentType, bool $finalize): RedirectResponse
    {
        abort_unless(in_array($documentType, [
            OutpatientClinicalDocument::TYPE_NURSING_ASSESSMENT,
            OutpatientClinicalDocument::TYPE_MEDICAL_ASSESSMENT,
        ], true), 404);

        Gate::authorize(OutpatientDocumentationDefinition::capability($documentType));
        $validated = $finalize
            ? $request->validate([
                'expected_version' => ['required', 'integer', 'min:1'],
            ])
            : $request->validate([
                'definition_version' => ['required', Rule::in([OutpatientClinicalDocument::DEFINITION_VERSION])],
                'expected_version' => ['required', 'integer', 'min:0'],
                'fields' => ['required', 'array'],
            ]);
        $user = $request->user();
        assert($user !== null);

        $this->documentationService->save(
            encounter: $encounter,
            actor: $user,
            documentType: $documentType,
            fields: $finalize ? null : $validated['fields'],
            expectedVersion: (int) $validated['expected_version'],
            finalize: $finalize,
        );

        return redirect()
            ->route('pemeriksaan.rawat-jalan.show', $encounter)
            ->with('success', $finalize ? 'Catatan klinis difinalisasi.' : 'Draf catatan klinis disimpan.');
    }

    /** @return array<string, mixed> */
    private function documentProjection(OutpatientClinicalDocument $document): array
    {
        return [
            'public_id' => $document->public_id,
            'document_type' => $document->document_type,
            'document_state' => $document->document_state,
            'definition_version' => $document->definition_version,
            'version' => $document->version,
            'fields' => $document->fields,
            'author_name' => $document->author?->name,
            'updated_at' => $document->updated_at?->toIso8601String(),
            'finalized_at' => $document->finalized_at?->toIso8601String(),
            'finalized_by_name' => $document->finalizedBy?->name,
        ];
    }

    /** @param array<int, OutpatientClinicalDocument> $documents */
    private function documentationFingerprint(array $documents): string
    {
        $sources = collect($documents)
            ->map(fn (OutpatientClinicalDocument $document): string => implode(':', [
                $document->public_id,
                $document->definition_version,
                $document->version,
                $document->document_state,
            ]))
            ->sort()
            ->implode('|');

        return hash('sha256', $sources);
    }

    /**
     * @param  iterable<OutpatientClinicalDocument>  $documents
     * @return list<array<string, mixed>>
     */
    private function documentVersionProjections(iterable $documents): array
    {
        $projections = [];
        foreach ($documents as $document) {
            foreach ($document->versions as $version) {
                $projections[] = [
                    'public_id' => $version->public_id,
                    'document_type' => $document->document_type,
                    'version' => $version->version,
                    'state' => $version->document_state,
                    'fields' => $version->fields,
                    'actor_name' => $version->actor?->name,
                    'created_at' => $version->created_at?->toIso8601String(),
                    'finalized_at' => $version->finalized_at?->toIso8601String(),
                ];
            }
        }

        usort($projections, static fn (array $left, array $right): int => [
            $left['document_type'], $left['version'],
        ] <=> [
            $right['document_type'], $right['version'],
        ]);

        return $projections;
    }
}
