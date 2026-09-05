<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\ClinicalEntry;
use App\Models\Encounter;
use App\Models\InpatientBed;
use App\Models\InpatientClinicalDocument;
use App\Models\InpatientClinicalDocumentVersion;
use App\Models\InpatientDischarge;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientDischargeSummaryVersion;
use App\Models\InpatientLocationEvent;
use App\Models\InpatientWard;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Authorization\RoleCapabilityMatrix;
use App\Support\Clinical\LegacyLaboratoryCompatibilityProjection;
use App\Support\Emergency\EmergencyProjection;
use App\Support\Inpatient\InpatientDischargeActorPolicy;
use App\Support\Inpatient\InpatientDischargeCodingSourceActorPolicy;
use App\Support\Inpatient\InpatientDischargeCodingSourceDenied;
use App\Support\Inpatient\InpatientDischargeCodingSourceService;
use App\Support\Inpatient\InpatientDischargeSummaryActorPolicy;
use App\Support\Inpatient\InpatientDischargeSummaryDenied;
use App\Support\Inpatient\InpatientDischargeSummaryService;
use App\Support\Inpatient\InpatientDocumentationActorPolicy;
use App\Support\Inpatient\InpatientDocumentationDenied;
use App\Support\Inpatient\InpatientDocumentationService;
use App\Support\Inpatient\InpatientLocationHistoryProjection;
use App\Support\Inpatient\InpatientSummaryAddendumProjection;
use App\Support\Inpatient\InpatientWardReadModel;
use App\Support\Laboratory\LaboratoryProjection;
use App\Support\Pharmacy\PharmacyProjection;
use App\Support\Radiology\RadiologyProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InpatientExaminationController extends Controller
{
    public function __construct(
        private readonly InpatientWardReadModel $wardReadModel,
        private readonly InpatientDocumentationService $documentationService,
        private readonly InpatientDocumentationActorPolicy $actorPolicy,
        private readonly InpatientLocationHistoryProjection $locationHistoryProjection,
        private readonly InpatientDischargeSummaryService $dischargeSummaryService,
        private readonly InpatientDischargeSummaryActorPolicy $dischargeSummaryActorPolicy,
        private readonly InpatientDischargeActorPolicy $inpatientDischargeActorPolicy,
        private readonly InpatientDischargeCodingSourceService $dischargeCodingSourceService,
        private readonly InpatientDischargeCodingSourceActorPolicy $dischargeCodingSourceActorPolicy,
        private readonly InpatientSummaryAddendumProjection $summaryAddendumProjection,
        private readonly RadiologyProjection $radiologyProjection,
        private readonly LaboratoryProjection $laboratoryProjection,
        private readonly PharmacyProjection $pharmacyProjection,
        private readonly LegacyLaboratoryCompatibilityProjection $legacyLaboratoryProjection,
        private readonly EmergencyProjection $emergencyProjection,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);
        $q = trim((string) $request->query('q', ''));
        $ward = trim((string) $request->query('ward', $request->query('clinic', '')));
        $payer = trim((string) $request->query('payer', ''));
        $continueFrom = trim((string) $request->query('continue_from', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $actor = $request->user();
        assert($actor instanceof User);
        $canAccessCorrections = ! $actor->is_system_administrator
            && ! $actor->hasRole(RoleCapabilityMatrix::ROLE_ADMIN)
            && $actor->hasRole(RoleCapabilityMatrix::ROLE_PHYSICIAN)
            && ($actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_REQUEST)
                || $actor->canCapability(Capability::INPATIENT_SUMMARY_ADDENDUM_APPROVE));
        $correctionMode = $canAccessCorrections
            && trim((string) $request->query('scope', '')) === 'correction';
        $encounters = [];

        try {
            $query = Encounter::query()->syntheticOnly()->with('patient')
                ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
                ->when(
                    $correctionMode,
                    fn ($query) => $query
                        ->where('status', Encounter::STATUS_CLOSED)
                        ->whereHas('inpatientDischarge')
                        ->whereHas('inpatientRmCoding', fn ($coding) => $coding->where('coding_state', 'FINAL'))
                        ->whereHas('inpatientRmCompletenessReviews', fn ($review) => $review->where('review_state', 'SIGNED_OFF')),
                    fn ($query) => $query->whereIn('status', Encounter::EXAMINATION_STATUSES),
                );
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
                $query->whereHas('patient', fn ($patientQuery) => $patientQuery
                    ->where('full_name', $like, '%'.$q.'%')
                    ->orWhere('medical_record_number', $like, '%'.$q.'%'));
            }
            $encounters = $query->orderBy('registered_at')->limit(100)->get()
                ->map(fn (Encounter $encounter): array => $this->encounterRow($encounter))->all();
        } catch (\Throwable $exception) {
            report($exception);
        }

        return Inertia::render('pemeriksaan/rawat-jalan/index', [
            'variant' => 'rawat-inap',
            'indexPath' => '/pemeriksaan/rawat-inap',
            'showPathPrefix' => '/pemeriksaan/rawat-inap',
            'encounters' => $encounters,
            'clinics' => $this->wardReadModel->filterOptions(),
            'payerOptions' => $this->options([Encounter::PAYER_UMUM => 'Umum', Encounter::PAYER_BPJS => 'BPJS', Encounter::PAYER_LAINNYA => 'Lainnya']),
            'continueFromOptions' => $this->options([Encounter::CONTINUE_LANGSUNG => 'Langsung', Encounter::CONTINUE_DARI_IGD => 'Dari IGD', Encounter::CONTINUE_DARI_RJ => 'Dari Rawat Jalan']),
            'filters' => ['q' => $q, 'clinic' => $ward, 'payer' => $payer, 'continue_from' => $continueFrom, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'scope' => $correctionMode ? 'correction' : 'active'],
            'canOpen' => $request->user()?->canCapability(Capability::ENCOUNTER_OPEN) ?? false,
            'canAccessCorrections' => $canAccessCorrections,
        ]);
    }

    public function show(Request $request, Encounter $encounter): Response
    {
        Gate::authorize(Capability::ENCOUNTER_OPEN);
        abort_unless($encounter->care_setting === Encounter::CARE_SETTING_INPATIENT, 404);
        $encounter->load([
            'patient', 'clinicalEntries.author', 'inpatientBed.ward',
            'inpatientClinicalDocuments.author', 'inpatientClinicalDocuments.versions.actor',
            'inpatientDischargeSummary.assignedPhysician', 'inpatientDischargeSummary.versions.actor',
            'inpatientDischargeCodingSource.assignedPhysician', 'inpatientDischargeCodingSource.versions.actor',
            'inpatientDischarge',
        ]);
        $actor = $request->user();
        assert($actor instanceof User);

        $projection = $this->showProjection($encounter, $actor);
        $projection['inpatient_summary_addendum'] = $this->summaryAddendumProjection->forEncounter($encounter, $actor);
        $projection['radiology'] = $this->radiologyProjection->encounter($encounter, $actor);
        $projection['laboratory'] = $this->laboratoryProjection->encounter($encounter, $actor);
        $projection['pharmacy'] = $this->pharmacyProjection->encounter($encounter, $actor);
        $projection['laboratory']['orders'] = [
            ...$projection['laboratory']['orders'],
            ...$this->legacyLaboratoryProjection->encounter($encounter, $actor),
        ];
        $projection['source_emergency'] = $this->emergencyProjection->sourceForInpatient($encounter, $actor);

        return Inertia::render('pemeriksaan/rawat-inap/show', $projection);
    }

    public function saveDraft(Request $request, string $encounter, string $documentType): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $this->actorPolicy->authorize($actor, $documentType);
        $this->assertOnlyKeys($request, ['definition_version', 'expected_version', 'fields', 'idempotency_key']);
        $payload = $this->validatedMutationPayload($request, draft: true);
        try {
            $result = $this->documentationService->saveDraft(
                $encounter,
                $actor,
                $documentType,
                $payload['definition_version'],
                $payload['expected_version'],
                $payload['fields'],
                $payload['idempotency_key'],
                $request->attributes->get('request_id'),
            );
        } catch (InpatientDocumentationDenied $denial) {
            abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'Draf sudah tersimpan.' : 'Draf harian disimpan.');
    }

    public function finalize(Request $request, string $encounter, string $documentType): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $this->actorPolicy->authorize($actor, $documentType);
        $this->assertOnlyKeys($request, ['definition_version', 'expected_version', 'idempotency_key']);
        $payload = $this->validatedMutationPayload($request, draft: false);
        try {
            $result = $this->documentationService->finalize(
                $encounter,
                $actor,
                $documentType,
                $payload['definition_version'],
                $payload['expected_version'],
                $payload['idempotency_key'],
                $request->attributes->get('request_id'),
            );
        } catch (InpatientDocumentationDenied $denial) {
            abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'Finalisasi sudah tercatat.' : 'Dokumen harian difinalisasi.');
    }

    public function saveDischargeSummaryDraft(Request $request, string $encounter): RedirectResponse
    {
        Gate::authorize(Capability::INPATIENT_DISCHARGE_SUMMARY_WRITE);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->assertOnlyKeys($request, ['definition_version', 'expected_version', 'fields', 'idempotency_key']);
        $payload = $this->validatedDischargeSummaryPayload($request, draft: true);

        try {
            $result = $this->dischargeSummaryService->saveDraft(
                $encounter,
                $actor,
                $payload['definition_version'],
                $payload['expected_version'],
                $payload['fields'],
                $payload['idempotency_key'],
                $request->attributes->get('request_id'),
            );
        } catch (InpatientDischargeSummaryDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['discharge_summary' => __($denial->getMessage())]);
            }

            abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'Draf ringkasan pulang sudah tersimpan.' : 'Draf ringkasan pulang disimpan.');
    }

    public function finalizeDischargeSummary(Request $request, string $encounter): RedirectResponse
    {
        Gate::authorize(Capability::INPATIENT_DISCHARGE_SUMMARY_WRITE);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->assertOnlyKeys($request, ['definition_version', 'expected_version', 'idempotency_key']);
        $payload = $this->validatedDischargeSummaryPayload($request, draft: false);

        try {
            $result = $this->dischargeSummaryService->finalize(
                $encounter,
                $actor,
                $payload['definition_version'],
                $payload['expected_version'],
                $payload['idempotency_key'],
                $request->attributes->get('request_id'),
            );
        } catch (InpatientDischargeSummaryDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['discharge_summary' => __($denial->getMessage())]);
            }

            abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'Finalisasi ringkasan pulang sudah tercatat.' : 'Ringkasan pulang dijadikan Final.');
    }

    public function saveDischargeCodingSourceDraft(Request $request, string $encounter): RedirectResponse
    {
        Gate::authorize(Capability::INPATIENT_DISCHARGE_CODING_SOURCE_WRITE);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->assertOnlyKeys($request, ['definition_version', 'expected_version', 'fields', 'idempotency_key']);
        $payload = $this->validatedDischargeCodingSourcePayload($request, true);
        try {
            $result = $this->dischargeCodingSourceService->saveDraft($encounter, $actor, $payload['definition_version'], $payload['expected_version'], $payload['fields'], $payload['idempotency_key'], $request->attributes->get('request_id'));
        } catch (InpatientDischargeCodingSourceDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['discharge_coding_source' => __($denial->getMessage())]);
            }abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)->with('success', $result->replayed ? 'Draf diagnosis akhir sudah tersimpan.' : 'Draf diagnosis akhir disimpan.');
    }

    public function finalizeDischargeCodingSource(Request $request, string $encounter): RedirectResponse
    {
        Gate::authorize(Capability::INPATIENT_DISCHARGE_CODING_SOURCE_WRITE);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->assertOnlyKeys($request, ['definition_version', 'expected_version', 'idempotency_key']);
        $payload = $this->validatedDischargeCodingSourcePayload($request, false);
        try {
            $result = $this->dischargeCodingSourceService->finalize($encounter, $actor, $payload['definition_version'], $payload['expected_version'], $payload['idempotency_key'], $request->attributes->get('request_id'));
        } catch (InpatientDischargeCodingSourceDenied $denial) {
            if ($request->header('X-Inertia') === 'true') {
                return back()->withErrors(['discharge_coding_source' => __($denial->getMessage())]);
            }abort($denial->status, __($denial->getMessage()));
        }

        return redirect()->route('pemeriksaan.rawat-inap.show', $encounter)->with('success', $result->replayed ? 'Finalisasi diagnosis akhir sudah tercatat.' : 'Diagnosis dan prosedur akhir dijadikan Final.');
    }

    /** @return array<string, mixed> */
    private function showProjection(Encounter $encounter, User $actor): array
    {
        $documents = $encounter->inpatientClinicalDocuments
            ->sort(static function (InpatientClinicalDocument $left, InpatientClinicalDocument $right): int {
                $recentFirst = [
                    $right->service_date->toDateString(),
                    $right->created_at?->toIso8601String() ?? '',
                    $right->id,
                ] <=> [
                    $left->service_date->toDateString(),
                    $left->created_at?->toIso8601String() ?? '',
                    $left->id,
                ];

                return $recentFirst !== 0 ? $recentFirst : [$left->document_type, $left->author->name]
                    <=> [$right->document_type, $right->author->name];
            })->values();
        $versions = [];
        foreach ($documents as $document) {
            foreach ($document->versions as $version) {
                $versions[] = [
                    'public_id' => $version->public_id,
                    'document_public_id' => $document->public_id,
                    'document_type' => $document->document_type,
                    'state' => $version->document_state,
                    'version' => $version->version,
                    'definition_version' => $version->definition_version,
                    'service_date' => $version->service_date->toDateString(),
                    'fields' => $version->fields,
                    'author_name' => $document->author->name,
                    'actor_name' => $version->actor->name,
                    'encounter_public_id' => $version->encounter_public_id,
                    'care_setting' => $version->care_setting,
                    'placement_snapshot' => [
                        'ward_public_id' => $version->ward_public_id, 'ward_code' => $version->ward_code,
                        'ward_display_name' => $version->ward_display_name, 'bed_public_id' => $version->bed_public_id,
                        'bed_code' => $version->bed_code, 'bed_display_name' => $version->bed_display_name,
                        'room_label' => $version->room_label, 'service_class' => $version->service_class,
                    ],
                    'encounter_status_snapshot' => $version->encounter_status,
                    'finalized_at' => $version->finalized_at?->toIso8601String(),
                    'created_at' => $version->created_at?->toIso8601String(),
                ];
            }
        }
        usort($versions, static fn (array $left, array $right): int => [
            $left['service_date'], $left['created_at'], $left['document_type'], $left['author_name'], $left['version'],
        ] <=> [
            $right['service_date'], $right['created_at'], $right['document_type'], $right['author_name'], $right['version'],
        ]);

        $today = now('Asia/Jakarta')->toDateString();
        $bed = $encounter->getRelation('inpatientBed');
        $ward = $bed instanceof InpatientBed ? $bed->getRelation('ward') : null;
        $managedPlacementActive = $bed instanceof InpatientBed
            && $bed->state === InpatientBed::STATE_ACTIVE
            && $ward instanceof InpatientWard
            && $ward->state === InpatientWard::STATE_ACTIVE
            && in_array($encounter->status, Encounter::BED_OCCUPYING_STATUSES, true);
        $permissions = [];
        $actions = [];
        foreach (['nursing' => InpatientClinicalDocument::TYPE_NURSING_DAILY, 'medical' => InpatientClinicalDocument::TYPE_MEDICAL_DAILY] as $key => $type) {
            $own = $documents->first(fn (InpatientClinicalDocument $document): bool => $document->document_type === $type
                && $document->service_date->toDateString() === $today && $document->author_user_id === $actor->id);
            $authorized = $managedPlacementActive && $this->actorPolicy->can($actor, $type);
            $mutable = $authorized && (! $own instanceof InpatientClinicalDocument || $own->document_state === InpatientClinicalDocument::STATE_DRAFT);
            $canFinalize = $authorized && $own instanceof InpatientClinicalDocument && $own->document_state === InpatientClinicalDocument::STATE_DRAFT;
            $permissions[$key] = [
                'can_save_draft' => $mutable,
                'can_finalize' => $canFinalize,
                'editable_document_public_id' => $own instanceof InpatientClinicalDocument ? $own->public_id : null,
            ];
            $actions[$key] = [
                'save_draft_url' => $mutable ? route('pemeriksaan.rawat-inap.documents.draft', [$encounter, $type], false) : null,
                'finalize_url' => $canFinalize ? route('pemeriksaan.rawat-inap.documents.finalize', [$encounter, $type], false) : null,
            ];
        }
        $dischargeSummary = $encounter->getRelation('inpatientDischargeSummary');
        $dischargeCodingSource = $encounter->getRelation('inpatientDischargeCodingSource');
        $dischargeAuthorized = $managedPlacementActive
            && $this->dischargeSummaryActorPolicy->can($actor)
            && (! $dischargeSummary instanceof InpatientDischargeSummary
                || $dischargeSummary->assigned_physician_user_id === $actor->id);
        $dischargeMutable = $dischargeAuthorized
            && (! $dischargeSummary instanceof InpatientDischargeSummary
                || $dischargeSummary->summary_state === InpatientDischargeSummary::STATE_DRAFT);
        $dischargeCanFinalize = $dischargeAuthorized
            && $dischargeSummary instanceof InpatientDischargeSummary
            && $dischargeSummary->summary_state === InpatientDischargeSummary::STATE_DRAFT;
        $inpatientDischarge = $encounter->getRelation('inpatientDischarge');
        $currentLocationSequence = (int) InpatientLocationEvent::query()
            ->where('encounter_id', $encounter->id)->max('sequence');
        $canExecuteDischarge = $managedPlacementActive
            && ! $inpatientDischarge instanceof InpatientDischarge
            && $dischargeSummary instanceof InpatientDischargeSummary
            && $dischargeSummary->summary_state === InpatientDischargeSummary::STATE_FINAL
            && $dischargeSummary->definition_version === InpatientDischargeSummary::DEFINITION_VERSION
            && $dischargeSummary->assigned_physician_user_id === $actor->id
            && $dischargeCodingSource instanceof InpatientDischargeCodingSource
            && $dischargeCodingSource->source_state === InpatientDischargeCodingSource::STATE_FINAL
            && $dischargeCodingSource->assigned_physician_user_id === $actor->id
            && $this->inpatientDischargeActorPolicy->can($actor);

        return [
            'variant' => 'rawat-inap',
            'indexPath' => '/pemeriksaan/rawat-inap',
            'encounter' => array_merge($this->encounterDetail($encounter), [
                'placement' => $bed instanceof InpatientBed && $ward instanceof InpatientWard ? [
                    'ward_public_id' => $ward->public_id,
                    'ward_code' => $ward->code,
                    'ward_display_name' => $ward->display_name,
                    'bed_public_id' => $bed->public_id,
                    'bed_code' => $bed->code,
                    'bed_display_name' => $bed->display_name,
                    'room_label' => $bed->room_label,
                    'service_class' => $bed->service_class,
                ] : null,
            ]),
            'documentation' => [
                'available' => $managedPlacementActive,
                'unavailable_reason' => $managedPlacementActive
                    ? null
                    : 'Daily documentation is unavailable until an active ward and bed assignment is linked.',
                'definition_version' => InpatientClinicalDocument::DEFINITION_VERSION,
                'documents' => $documents->map(function (InpatientClinicalDocument $document) use ($actor): array {
                    $latest = $document->versions->sortByDesc('version')->first();

                    return [
                        'public_id' => $document->public_id, 'document_type' => $document->document_type,
                        'state' => $document->document_state, 'service_date' => $document->service_date->toDateString(),
                        'definition_version' => $document->definition_version,
                        'version' => $document->version, 'fields' => $document->fields,
                        'author' => ['public_id' => $document->author->public_id, 'name' => $document->author->name],
                        'author_name' => $document->author->name,
                        'is_current_actor_document' => $document->author_user_id === $actor->id,
                        'placement_snapshot' => $latest instanceof InpatientClinicalDocumentVersion ? [
                            'ward_public_id' => $latest->ward_public_id, 'ward_code' => $latest->ward_code,
                            'ward_display_name' => $latest->ward_display_name, 'bed_public_id' => $latest->bed_public_id,
                            'bed_code' => $latest->bed_code, 'bed_display_name' => $latest->bed_display_name,
                            'room_label' => $latest->room_label, 'service_class' => $latest->service_class,
                        ] : null,
                        'encounter_status_snapshot' => $latest?->encounter_status,
                        'finalized_at' => $document->finalized_at?->toIso8601String(),
                        'created_at' => $document->created_at?->toIso8601String(),
                        'updated_at' => $document->updated_at?->toIso8601String(),
                    ];
                })->all(),
                'versions' => $versions,
            ],
            'legacyEntries' => $this->legacyEntries($encounter),
            'permissions' => $permissions,
            'actions' => $actions,
            'discharge_summary' => [
                'definition_version' => InpatientDischargeSummary::DEFINITION_VERSION,
                'summary' => $dischargeSummary instanceof InpatientDischargeSummary ? [
                    'public_id' => $dischargeSummary->public_id,
                    'state' => $dischargeSummary->summary_state,
                    'version' => $dischargeSummary->version,
                    'definition_version' => $dischargeSummary->definition_version,
                    'fields' => $this->dischargeSummaryFields($dischargeSummary),
                    'assigned_physician' => [
                        'public_id' => $dischargeSummary->assignedPhysician?->public_id,
                        'name' => $dischargeSummary->assignedPhysician?->name,
                    ],
                    'created_at' => $dischargeSummary->created_at?->toIso8601String(),
                    'updated_at' => $dischargeSummary->updated_at?->toIso8601String(),
                    'finalized_at' => $dischargeSummary->finalized_at?->toIso8601String(),
                ] : null,
                'versions' => $dischargeSummary instanceof InpatientDischargeSummary
                    ? $dischargeSummary->versions->sortBy('version')->values()->map(fn (InpatientDischargeSummaryVersion $version): array => [
                        'public_id' => $version->public_id,
                        'state' => $version->summary_state,
                        'version' => $version->version,
                        'definition_version' => $version->definition_version,
                        'fields' => $this->dischargeSummaryFields($version),
                        'actor_name' => $version->actor?->name,
                        'created_at' => $version->created_at?->toIso8601String(),
                        'finalized_at' => $version->finalized_at?->toIso8601String(),
                    ])->all()
                    : [],
                'permission' => [
                    'can_save_draft' => $dischargeMutable,
                    'can_finalize' => $dischargeCanFinalize,
                ],
                'actions' => [
                    'save_draft_url' => $dischargeMutable
                        ? route('pemeriksaan.rawat-inap.discharge-summary.draft', $encounter, false)
                        : null,
                    'finalize_url' => $dischargeCanFinalize
                        ? route('pemeriksaan.rawat-inap.discharge-summary.finalize', $encounter, false)
                        : null,
                ],
            ],
            'inpatient_discharge_coding_source' => [
                'definition_version' => InpatientDischargeCodingSource::DEFINITION_VERSION,
                'source' => $dischargeCodingSource instanceof InpatientDischargeCodingSource ? ['public_id' => $dischargeCodingSource->public_id, 'state' => $dischargeCodingSource->source_state, 'version' => $dischargeCodingSource->version, 'definition_version' => $dischargeCodingSource->definition_version, 'fields' => $this->dischargeCodingSourceFields($dischargeCodingSource), 'assigned_physician' => ['public_id' => $dischargeCodingSource->assignedPhysician?->public_id, 'name' => $dischargeCodingSource->assignedPhysician?->name], 'created_at' => $dischargeCodingSource->created_at?->toIso8601String(), 'updated_at' => $dischargeCodingSource->updated_at?->toIso8601String(), 'finalized_at' => $dischargeCodingSource->finalized_at?->toIso8601String()] : null,
                'versions' => $dischargeCodingSource instanceof InpatientDischargeCodingSource ? $dischargeCodingSource->versions->sortBy('version')->values()->map(fn (InpatientDischargeCodingSourceVersion $v): array => ['public_id' => $v->public_id, 'state' => $v->source_state, 'version' => $v->version, 'definition_version' => $v->definition_version, 'fields' => $this->dischargeCodingSourceFields($v), 'actor_name' => $v->actor?->name, 'created_at' => $v->created_at?->toIso8601String(), 'finalized_at' => $v->finalized_at?->toIso8601String()])->all() : [],
                'permission' => ['can_save_draft' => $codingMutable = $managedPlacementActive && $this->dischargeCodingSourceActorPolicy->can($actor) && $dischargeSummary instanceof InpatientDischargeSummary && $dischargeSummary->assigned_physician_user_id === $actor->id && (! $dischargeCodingSource instanceof InpatientDischargeCodingSource || $dischargeCodingSource->source_state === InpatientDischargeCodingSource::STATE_DRAFT), 'can_finalize' => $codingMutable && $dischargeCodingSource instanceof InpatientDischargeCodingSource && $dischargeCodingSource->source_state === InpatientDischargeCodingSource::STATE_DRAFT],
                'actions' => ['save_draft_url' => $codingMutable ? route('pemeriksaan.rawat-inap.discharge-coding-source.draft', $encounter, false) : null, 'finalize_url' => $codingMutable && $dischargeCodingSource instanceof InpatientDischargeCodingSource ? route('pemeriksaan.rawat-inap.discharge-coding-source.finalize', $encounter, false) : null],
            ],
            'inpatient_discharge' => [
                'disposition' => [
                    'code' => InpatientDischarge::DISPOSITION_ROUTINE_HOME,
                    'label' => InpatientDischarge::DISPOSITION_ROUTINE_HOME_LABEL,
                ],
                'record' => $inpatientDischarge instanceof InpatientDischarge ? [
                    'public_id' => $inpatientDischarge->public_id,
                    'disposition_code' => $inpatientDischarge->disposition_code,
                    'disposition_label' => $inpatientDischarge->disposition_label,
                    'discharge_summary_public_id' => $inpatientDischarge->discharge_summary_public_id,
                    'discharge_summary_version' => $inpatientDischarge->discharge_summary_version,
                    'location_sequence' => $inpatientDischarge->location_sequence,
                    'source_bed_public_id' => $inpatientDischarge->source_bed_public_id,
                    'source_bed_code' => $inpatientDischarge->source_bed_code,
                    'encounter_status_before' => $inpatientDischarge->encounter_status_before,
                    'encounter_status_after' => $inpatientDischarge->encounter_status_after,
                    'discharged_at' => $inpatientDischarge->discharged_at->toIso8601String(),
                ] : null,
                'permission' => ['can_execute' => $canExecuteDischarge],
                'requirements' => [
                    'expected_summary_version' => $dischargeSummary instanceof InpatientDischargeSummary
                        && $dischargeSummary->summary_state === InpatientDischargeSummary::STATE_FINAL
                        ? $dischargeSummary->version : null,
                    'current_location_sequence' => $currentLocationSequence,
                    'source_bed_public_id' => $bed instanceof InpatientBed ? $bed->public_id : null,
                ],
                'actions' => [
                    'execute_url' => $canExecuteDischarge
                        ? route('pemeriksaan.rawat-inap.discharge.execute', $encounter, false) : null,
                ],
            ],
            'location_history' => $this->locationHistoryProjection->forEncounter($encounter, $actor),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedDischargeSummaryPayload(Request $request, bool $draft): array
    {
        $input = $request->all();
        $input['idempotency_key'] ??= $request->header('Idempotency-Key');
        $rules = [
            'definition_version' => ['required', 'string', Rule::in([InpatientDischargeSummary::DEFINITION_VERSION])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ];
        if ($draft) {
            $rules['fields'] = ['present', 'array:'.implode(',', InpatientDischargeSummary::NARRATIVE_FIELDS)];
            foreach (InpatientDischargeSummary::NARRATIVE_FIELDS as $field) {
                $rules['fields.'.$field] = ['sometimes', 'string', 'max:10000'];
            }
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $rules)->validate();

        return $validated;
    }

    /** @return array<string,mixed> */
    private function validatedDischargeCodingSourcePayload(Request $request, bool $draft): array
    {
        $input = $request->all();
        $input['idempotency_key'] ??= $request->header('Idempotency-Key');
        $rules = ['definition_version' => ['required', 'string', Rule::in([InpatientDischargeCodingSource::DEFINITION_VERSION])], 'expected_version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/']];
        if ($draft) {
            $rules['fields'] = ['required', 'array:principal_diagnosis_statement,secondary_diagnosis_statements,procedure_attestation,performed_procedure_statements'];
            $rules['fields.principal_diagnosis_statement'] = ['present', 'nullable', 'string', 'max:500'];
            $rules['fields.secondary_diagnosis_statements'] = ['required', 'array', 'max:20'];
            $rules['fields.secondary_diagnosis_statements.*'] = ['string', 'min:1', 'max:500', 'distinct:strict'];
            $rules['fields.procedure_attestation'] = ['required', 'string', Rule::in([InpatientDischargeCodingSource::ATTESTATION_NONE, InpatientDischargeCodingSource::ATTESTATION_RECORDED])];
            $rules['fields.performed_procedure_statements'] = ['required', 'array', 'max:20'];
            $rules['fields.performed_procedure_statements.*'] = ['string', 'min:1', 'max:500', 'distinct:strict'];
        }

        return Validator::make($input, $rules)->validate();
    }

    /** @return array<string,mixed> */
    private function dischargeCodingSourceFields(InpatientDischargeCodingSource|InpatientDischargeCodingSourceVersion $r): array
    {
        return ['principal_diagnosis_statement' => (string) ($r->principal_diagnosis_statement ?? ''), 'secondary_diagnosis_statements' => $r->secondary_diagnosis_statements ?? [], 'procedure_attestation' => $r->procedure_attestation, 'performed_procedure_statements' => $r->performed_procedure_statements ?? []];
    }

    /** @return array<string, string> */
    private function dischargeSummaryFields(InpatientDischargeSummary|InpatientDischargeSummaryVersion $record): array
    {
        return collect(InpatientDischargeSummary::NARRATIVE_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => (string) ($record->getAttribute($field) ?? '')])
            ->all();
    }

    /** @return array<string, mixed> */
    private function validatedMutationPayload(Request $request, bool $draft): array
    {
        $input = $request->all();
        $input['idempotency_key'] ??= $request->header('Idempotency-Key');
        $rules = [
            'definition_version' => ['required', 'string', Rule::in([InpatientClinicalDocument::DEFINITION_VERSION])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,254}\z/'],
        ];
        if ($draft) {
            $rules['fields'] = ['present', 'array'];
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $rules)->validate();

        return $validated;
    }

    /** @param list<string> $allowed */
    private function assertOnlyKeys(Request $request, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($request->except('_token')), $allowed));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['documentation' => 'The request contains unsupported attributes.']);
        }
    }

    /** @return array<string, mixed> */
    private function encounterRow(Encounter $encounter): array
    {
        return [
            'public_id' => $encounter->public_id, 'care_setting' => $encounter->care_setting, 'status' => $encounter->status,
            'clinic_name' => $encounter->ward_name, 'ward_name' => $encounter->ward_name,
            'ward_class' => $encounter->ward_class, 'bed_code' => $encounter->bed_code,
            'continue_from' => $encounter->continue_from, 'doctor_name' => null,
            'schedule_label' => $encounter->bed_code, 'payer_type' => $encounter->payer_type,
            'queue_number' => $encounter->queue_number, 'registered_at' => $encounter->registered_at->toIso8601String(),
            'visit_date' => $encounter->visit_date?->toDateString(), 'chief_complaint' => $encounter->chief_complaint,
            'patient' => [
                'public_id' => $encounter->patient?->public_id,
                'medical_record_number' => $encounter->patient?->medical_record_number,
                'full_name' => $encounter->patient?->full_name,
                'date_of_birth' => $encounter->patient?->date_of_birth->toDateString(),
                'sex' => $encounter->patient?->sex,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function encounterDetail(Encounter $encounter): array
    {
        $row = $this->encounterRow($encounter);
        $patient = is_array($row['patient']) ? $row['patient'] : [];

        return array_merge($row, [
            'patient' => array_merge($patient, ['nik' => $encounter->patient?->nik]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function legacyEntries(Encounter $encounter): array
    {
        return array_values($encounter->clinicalEntries->sortBy('created_at')->values()->map(fn (ClinicalEntry $entry): array => [
            'public_id' => $entry->public_id, 'entry_type' => $entry->entry_type, 'body' => $entry->body,
            'created_at' => $entry->created_at?->toIso8601String(), 'author_name' => $entry->author?->name, 'read_only' => true,
        ])->all());
    }

    /** @param array<string, string> $map
     * @return list<array{value: string, label: string}>
     */
    private function options(array $map): array
    {
        return array_values(collect($map)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all());
    }
}
