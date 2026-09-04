<?php

namespace App\Http\Controllers\Outpatient;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Encounter;
use App\Models\LabServiceRequest;
use App\Models\OutpatientClinicalDocument;
use App\Models\OutpatientRmCompletenessReview;
use App\Support\Authorization\Capability;
use App\Support\Clinical\OutpatientAmendmentProjection;
use App\Support\Clinical\OutpatientRmCompletenessService;
use App\Support\Registration\ClinicBookingSurface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OutpatientRmController extends Controller
{
    public function __construct(
        private readonly OutpatientRmCompletenessService $completenessService,
        private readonly OutpatientAmendmentProjection $amendmentProjection,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);
        Gate::authorize(Capability::RMIK_REVIEW);

        $q = trim((string) $request->query('q', ''));
        $clinic = trim((string) $request->query('clinic', ''));
        $payer = trim((string) $request->query('payer', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $encounters = [];
        $clinics = [];

        try {
            $clinics = Clinic::query()
                ->where('is_active', true)
                ->where('booking_surface', ClinicBookingSurface::OUTPATIENT)
                ->orderBy('name')
                ->get()
                ->map(fn (Clinic $row): array => ['value' => $row->name, 'label' => $row->name])->all();
            $query = Encounter::query()
                ->syntheticOnly()
                ->with([
                    'patient',
                    'outpatientClinicalDocuments',
                    'latestOutpatientDisposition',
                    'outpatientRmCompletenessReviews.items',
                    'labServiceRequests',
                    'laboratoryOrders.specimenAttempts',
                    'laboratoryOrders.resultVersions.acknowledgement',
                    'radiologyOrders.reportVersions.acknowledgement',
                    'pharmacyPrescriptions.preparations.handover',
                ])
                ->where('care_setting', Encounter::CARE_SETTING_OUTPATIENT)
                ->where(function ($builder): void {
                    $builder->where('status', Encounter::STATUS_READY_FOR_RM)
                        ->orWhere(function ($closed): void {
                            $closed->where('status', Encounter::STATUS_CLOSED)
                                ->whereHas('outpatientRmCompletenessReviews', fn ($reviews) => $reviews
                                    ->where('review_state', OutpatientRmCompletenessReview::STATE_SIGNED_OFF));
                        });
                });

            if ($clinic !== '') {
                $query->where('clinic_name', $clinic);
            }
            if ($payer !== '') {
                $query->where('payer_type', $payer);
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

            $encounters = $query->orderBy('updated_at')->limit(100)->get()
                ->map(function (Encounter $encounter): array {
                    $latest = $encounter->outpatientRmCompletenessReviews->sortByDesc('version')->first();
                    $isArchived = $encounter->status === Encounter::STATUS_CLOSED
                        && $latest?->review_state === OutpatientRmCompletenessReview::STATE_SIGNED_OFF;
                    if ($isArchived) {
                        $state = 'COMPLETE';
                        $blockerCount = $latest->items
                            ->where('is_blocking', true)
                            ->where('is_complete', false)
                            ->count();
                    } else {
                        $snapshot = $this->completenessService->snapshot($encounter);
                        $reviewIsCurrent = $latest !== null
                            && hash_equals($latest->source_fingerprint, $snapshot['source_fingerprint']);
                        $state = ! $reviewIsCurrent
                            ? 'UNREVIEWED'
                            : ($snapshot['blockers'] === [] ? 'COMPLETE' : 'INCOMPLETE');
                        $blockerCount = count($snapshot['blockers']);
                    }
                    $activeLabOrderCount = $encounter->labServiceRequests
                        ->where('status', LabServiceRequest::STATUS_ACTIVE)
                        ->count();

                    return [
                        'public_id' => $encounter->public_id,
                        'status' => $encounter->status,
                        'clinic_name' => $encounter->clinic_name,
                        'doctor_name' => $encounter->doctor_name,
                        'payer_type' => $encounter->payer_type,
                        'admission_mode' => $encounter->admission_mode,
                        'queue_number' => $encounter->queue_number,
                        'registered_at' => $encounter->registered_at->toIso8601String(),
                        'visit_date' => $encounter->visit_date?->toDateString(),
                        'entry_count' => $encounter->outpatientClinicalDocuments->count(),
                        'active_lab_order_count' => $activeLabOrderCount,
                        'patient' => [
                            'public_id' => $encounter->patient?->public_id,
                            'medical_record_number' => $encounter->patient?->medical_record_number,
                            'full_name' => $encounter->patient?->full_name,
                            'date_of_birth' => $encounter->patient?->date_of_birth->toDateString(),
                            'sex' => $encounter->patient?->sex,
                        ],
                        'completeness' => [
                            'state' => $state,
                            'label' => match ($state) {
                                'COMPLETE' => 'Lengkap',
                                'INCOMPLETE' => 'Belum lengkap',
                                default => 'Belum diperiksa',
                            },
                            'review_url' => route('rm.rawat-jalan.show', $encounter),
                        ],
                        'completeness_status' => match ($state) {
                            'COMPLETE' => 'COMPLETE',
                            'INCOMPLETE' => 'INCOMPLETE',
                            default => 'NOT_REVIEWED',
                        },
                        'blocker_count' => $blockerCount,
                    ];
                })->all();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('rm/rawat-jalan', [
            'encounters' => $encounters,
            'clinics' => $clinics,
            'payerOptions' => [
                ['value' => Encounter::PAYER_UMUM, 'label' => 'Umum'],
                ['value' => Encounter::PAYER_BPJS, 'label' => 'BPJS'],
                ['value' => Encounter::PAYER_LAINNYA, 'label' => 'Lainnya'],
            ],
            'filters' => ['q' => $q, 'clinic' => $clinic, 'payer' => $payer, 'date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);
    }

    public function show(Request $request, Encounter $encounter): Response
    {
        Gate::authorize(Capability::RMIK_REVIEW);
        abort_unless($encounter->care_setting === Encounter::CARE_SETTING_OUTPATIENT, 404);
        $encounter->load([
            'patient',
            'outpatientClinicalDocuments.author',
            'outpatientClinicalDocuments.finalizedBy',
            'outpatientClinicalDocuments.versions.actor',
            'latestOutpatientDisposition',
            'outpatientRmCompletenessReviews.items',
            'outpatientRmCompletenessReviews.reviewedBy',
            'outpatientRmCompletenessReviews.signedOffBy',
            'outpatientPostClosureAmendmentRequests.requester',
            'outpatientPostClosureAmendmentRequests.decidedBy',
            'outpatientPostClosureAmendmentRequests.originalDocument',
            'outpatientPostClosureAmendmentRequests.addendum.author',
            'outpatientPostClosureAmendmentRequests.addendum.finalizedBy',
            'outpatientPostClosureAmendmentRequests.renewedReviews.items',
            'outpatientPostClosureAmendmentRequests.renewedReviews.reviewedBy',
            'outpatientPostClosureAmendmentRequests.renewedReviews.signedOffBy',
            'labServiceRequests',
            'pharmacyPrescriptions.preparations.handover',
        ]);
        /** @var OutpatientRmCompletenessReview|null $review */
        $review = $encounter->outpatientRmCompletenessReviews->sortByDesc('version')->first();
        $isReviewable = $encounter->status === Encounter::STATUS_READY_FOR_RM;
        $isSignedClosed = $encounter->status === Encounter::STATUS_CLOSED
            && $review?->review_state === OutpatientRmCompletenessReview::STATE_SIGNED_OFF;
        abort_unless($isReviewable || $isSignedClosed, 422);
        if ($isSignedClosed) {
            $reviewProjection = $this->archivedReviewProjection($review);
            $blockers = $this->archivedBlockers($review);
        } else {
            $snapshot = $this->completenessService->snapshot($encounter);
            $reviewProjection = $this->currentReviewProjection($review, $snapshot);
            $blockers = collect($snapshot['items'])
                ->whereIn('item_code', $snapshot['blockers'])
                ->map(fn (array $item): array => [
                    'code' => $item['item_code'],
                    'label' => $item['label'],
                    'reason' => 'Sumber yang diwajibkan belum lengkap atau masih aktif.',
                ])->values()->all();
        }
        $user = $request->user();
        assert($user !== null);

        return Inertia::render('rm/rawat-jalan/show', [
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
            'clinicalSources' => $encounter->outpatientClinicalDocuments->sortBy('document_type')->values()
                ->map(fn (OutpatientClinicalDocument $document): array => [
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
                ])->all(),
            'documentVersions' => $this->documentVersionProjections($encounter->outpatientClinicalDocuments),
            'review' => $reviewProjection,
            'blockers' => $blockers,
            'permissions' => [
                ...$this->amendmentProjection->topPermissions($encounter, $user),
                'can_save_review' => $isReviewable && $user->canCapability(Capability::RMIK_REVIEW),
                'can_signoff' => $isReviewable && $user->canCapability(Capability::RMIK_COMPLETENESS_SIGNOFF),
            ],
            'actions' => [
                ...$this->amendmentProjection->topActions($encounter, $user),
                'save_review_url' => route('rm.rawat-jalan.reviews.store', $encounter),
                'signoff_url' => route('rm.rawat-jalan.signoff', $encounter),
            ],
            'amendmentReasonOptions' => $this->amendmentProjection->reasonOptions(),
            'amendments' => $this->amendmentProjection->amendments($encounter, $user),
        ]);
    }

    public function saveReview(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::RMIK_REVIEW);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'source_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        $user = $request->user();
        assert($user !== null);
        $this->completenessService->saveReview(
            $encounter,
            $user,
            (int) $validated['expected_version'],
            $validated['source_fingerprint'],
        );

        return redirect()->route('rm.rawat-jalan.show', $encounter)->with('success', 'Pemeriksaan kelengkapan disimpan.');
    }

    public function signoff(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::RMIK_COMPLETENESS_SIGNOFF);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'size:64'],
        ]);
        $user = $request->user();
        assert($user !== null);
        $this->completenessService->signoff($encounter, $user, (int) $validated['expected_version'], $validated['source_fingerprint']);

        return redirect()->route('rm.rawat-jalan.show', $encounter)->with('success', 'Kelengkapan RM ditandatangani dan kunjungan ditutup.');
    }

    /** @param array{source_fingerprint: string, items: list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>, blockers: list<string>} $snapshot
     * @return array<string, mixed>
     */
    private function currentReviewProjection(?OutpatientRmCompletenessReview $review, array $snapshot): array
    {
        $reviewIsCurrent = $review !== null
            && hash_equals($review->source_fingerprint, $snapshot['source_fingerprint']);
        $items = collect($snapshot['items'])->map(fn (array $item): array => [
            'code' => $item['item_code'],
            'label' => $item['label'],
            'status' => $item['is_complete'] ? 'PASS' : 'FAIL',
            'reason' => $item['is_complete'] ? null : 'Sumber belum lengkap.',
        ])->all();

        return [
            'public_id' => $review?->public_id,
            'definition_version' => $review === null ? OutpatientRmCompletenessReview::DEFINITION_VERSION : $review->definition_version,
            'status' => $review?->review_state === OutpatientRmCompletenessReview::STATE_SIGNED_OFF
                ? 'SIGNED_OFF'
                : (! $reviewIsCurrent ? 'NOT_REVIEWED' : ($snapshot['blockers'] === [] ? 'COMPLETE' : 'INCOMPLETE')),
            'version' => $review === null ? 0 : $review->version,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'checklist_items' => $items,
            'state' => $review === null ? OutpatientRmCompletenessReview::STATE_DRAFT : $review->review_state,
            'items' => $snapshot['items'],
            'reviewer_name' => $review?->reviewedBy?->name,
            'reviewed_by_name' => $review?->reviewedBy?->name,
            'reviewed_at' => $review?->reviewed_at?->toIso8601String(),
            'signed_off_by_name' => $review?->signedOffBy?->name,
            'signed_off_at' => $review?->signed_off_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function archivedReviewProjection(OutpatientRmCompletenessReview $review): array
    {
        $storedItems = $review->items->map(fn ($item): array => [
            'item_code' => $item->item_code,
            'label' => $item->label,
            'is_blocking' => $item->is_blocking,
            'is_complete' => $item->is_complete,
            'source_reference' => $item->source_reference,
        ])->values()->all();

        return [
            'public_id' => $review->public_id,
            'definition_version' => $review->definition_version,
            'status' => 'SIGNED_OFF',
            'version' => $review->version,
            'source_fingerprint' => $review->source_fingerprint,
            'checklist_items' => $review->items->map(fn ($item): array => [
                'code' => $item->item_code,
                'label' => $item->label,
                'status' => $item->is_complete ? 'PASS' : 'FAIL',
                'reason' => $item->is_complete ? null : 'Sumber belum lengkap saat sign-off.',
            ])->values()->all(),
            'state' => $review->review_state,
            'items' => $storedItems,
            'reviewer_name' => $review->reviewedBy?->name,
            'reviewed_by_name' => $review->reviewedBy?->name,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            'signed_off_by_name' => $review->signedOffBy?->name,
            'signed_off_at' => $review->signed_off_at?->toIso8601String(),
        ];
    }

    /** @return list<array{code: string, label: string, reason: string}> */
    private function archivedBlockers(OutpatientRmCompletenessReview $review): array
    {
        $blockers = [];
        foreach ($review->items as $item) {
            if ($item->is_blocking && ! $item->is_complete) {
                $blockers[] = [
                    'code' => $item->item_code,
                    'label' => $item->label,
                    'reason' => 'Item belum lengkap pada pemeriksaan tersimpan.',
                ];
            }
        }

        return $blockers;
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
