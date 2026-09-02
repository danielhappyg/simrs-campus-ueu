<?php

namespace App\Http\Controllers\Inpatient;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\InpatientDischargeCodingSource;
use App\Models\InpatientDischargeCodingSourceVersion;
use App\Models\InpatientDischargeSummary;
use App\Models\InpatientRmCoding;
use App\Models\InpatientRmCodingAssignment;
use App\Models\InpatientRmCodingVersion;
use App\Models\InpatientRmCompletenessReview;
use App\Models\User;
use App\Support\Authorization\Capability;
use App\Support\Inpatient\InpatientRmActorPolicy;
use App\Support\Inpatient\InpatientRmDenied;
use App\Support\Inpatient\InpatientRmService;
use App\Support\Inpatient\InpatientSummaryAddendumProjection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class InpatientRmController extends Controller
{
    public function __construct(
        private readonly InpatientRmService $service,
        private readonly InpatientRmActorPolicy $actorPolicy,
        private readonly InpatientSummaryAddendumProjection $summaryAddendumProjection,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Capability::ENCOUNTER_LIST);
        Gate::authorize(Capability::RMIK_REVIEW);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->actorPolicy->authorizeReview($actor);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'ward' => trim((string) $request->query('ward', '')),
            'payer' => trim((string) $request->query('payer', '')),
            'review_state' => trim((string) $request->query('review_state', '')),
            'discharged_from' => trim((string) $request->query('discharged_from', '')),
            'discharged_to' => trim((string) $request->query('discharged_to', '')),
        ];
        $query = Encounter::query()->syntheticOnly()
            ->with(['patient', 'inpatientDischarge', 'inpatientRmCoding.versions.assignments', 'inpatientRmCompletenessReviews.items'])
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where(function ($builder): void {
                $builder->where('status', Encounter::STATUS_READY_FOR_RM)
                    ->orWhere(function ($closed): void {
                        $closed->where('status', Encounter::STATUS_CLOSED)
                            ->whereHas('inpatientRmCompletenessReviews', fn ($reviews) => $reviews->where('review_state', InpatientRmCompletenessReview::STATE_SIGNED_OFF));
                    });
            });
        if ($filters['ward'] !== '') {
            $query->where('ward_name', $filters['ward']);
        }
        if ($filters['payer'] !== '') {
            $query->where('payer_type', $filters['payer']);
        }
        if ($filters['discharged_from'] !== '') {
            $query->whereHas('inpatientDischarge', fn ($discharge) => $discharge->whereDate('discharged_at', '>=', $filters['discharged_from']));
        }
        if ($filters['discharged_to'] !== '') {
            $query->whereHas('inpatientDischarge', fn ($discharge) => $discharge->whereDate('discharged_at', '<=', $filters['discharged_to']));
        }
        if ($filters['q'] !== '') {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->whereHas('patient', fn ($patient) => $patient
                ->where('full_name', $like, '%'.$filters['q'].'%')
                ->orWhere('medical_record_number', $like, '%'.$filters['q'].'%'));
        }
        $encounters = $query->orderBy('updated_at')->limit(100)->get()->map(function (Encounter $encounter): array {
            $latestReview = $encounter->inpatientRmCompletenessReviews->sortByDesc('version')->first();
            $coding = $encounter->inpatientRmCoding;
            $reviewCurrent = $latestReview !== null && $coding !== null
                && $latestReview->coding_version === $coding->version
                && hash_equals($latestReview->coding_digest, $coding->current_content_digest);
            $reviewStatus = $encounter->status === Encounter::STATUS_CLOSED
                ? 'SIGNED_OFF'
                : (! $reviewCurrent ? 'NOT_REVIEWED' : ($latestReview->blocker_count === 0 ? 'COMPLETE' : 'INCOMPLETE'));

            return [
                'public_id' => $encounter->public_id,
                'status' => $encounter->status,
                'discharged_at' => $encounter->inpatientDischarge?->discharged_at->toIso8601String(),
                'last_ward_name' => $encounter->ward_name,
                'payer_type' => $encounter->payer_type,
                'patient' => [
                    'medical_record_number' => $encounter->patient?->medical_record_number,
                    'full_name' => $encounter->patient?->full_name,
                ],
                'review_status' => $reviewStatus,
                'review_version' => $latestReview instanceof InpatientRmCompletenessReview ? $latestReview->version : 0,
            ];
        });
        if ($filters['review_state'] !== '') {
            $encounters = $encounters->where('review_status', $filters['review_state']);
        }
        $encounters = $encounters->values()->all();

        $wards = Encounter::query()->syntheticOnly()->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->whereNotNull('ward_name')->distinct()->orderBy('ward_name')->pluck('ward_name')
            ->map(fn (string $ward): array => ['value' => $ward, 'label' => $ward])->all();

        return Inertia::render('rm/rawat-inap', ['inpatient_rm' => [
            'filters' => $filters,
            'filter_options' => [
                'wards' => $wards,
                'payers' => [
                    ['value' => Encounter::PAYER_UMUM, 'label' => 'Umum'],
                    ['value' => Encounter::PAYER_BPJS, 'label' => 'BPJS'],
                    ['value' => Encounter::PAYER_LAINNYA, 'label' => 'Lainnya'],
                ],
                'review_states' => [
                    ['value' => 'NOT_REVIEWED', 'label' => 'Belum direview'],
                    ['value' => 'INCOMPLETE', 'label' => 'Ada blocker'],
                    ['value' => 'COMPLETE', 'label' => 'Siap signoff'],
                    ['value' => 'SIGNED_OFF', 'label' => 'Ditutup'],
                ],
            ],
            'encounters' => $encounters,
            'actions' => ['show_url' => rtrim(route('rm.rawat-inap.index'), '/')],
        ]]);
    }

    public function show(Request $request, Encounter $encounter): Response
    {
        Gate::authorize(Capability::RMIK_REVIEW);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->actorPolicy->authorizeReview($actor);
        abort_unless($encounter->care_setting === Encounter::CARE_SETTING_INPATIENT, 404);
        abort_unless(in_array($encounter->status, [Encounter::STATUS_READY_FOR_RM, Encounter::STATUS_CLOSED], true), 422);
        $encounter->load([
            'patient', 'inpatientDischarge.summaryVersion', 'inpatientDischarge.codingSourceVersion',
            'inpatientDischargeSummary.versions', 'inpatientDischargeCodingSource.versions',
            'inpatientClinicalDocuments', 'labServiceRequests',
            'inpatientRmCoding.versions.assignments', 'inpatientRmCoding.versions.actor',
            'inpatientRmCompletenessReviews.items',
            'inpatientRmCompletenessReviews.reviewedBy',
            'inpatientRmCompletenessReviews.signedOffBy',
        ]);
        $snapshot = $this->service->snapshot($encounter);
        $coding = $encounter->inpatientRmCoding()->first();
        $codingVersion = $coding instanceof InpatientRmCoding
            ? $coding->versions->sortByDesc('version')->first()
            : null;
        $latestReview = $encounter->inpatientRmCompletenessReviews->sortByDesc('version')->first();
        $source = $encounter->inpatientDischargeCodingSource()->first();
        $sourceVersion = $source instanceof InpatientDischargeCodingSource
            ? $source->versions->sortByDesc('version')->first()
            : null;

        return Inertia::render('rm/rawat-inap/show', ['inpatient_rm' => [
            'encounter' => [
                'public_id' => $encounter->public_id, 'status' => $encounter->status,
                'discharged_at' => $encounter->inpatientDischarge?->discharged_at?->toIso8601String(),
                'last_ward_name' => $encounter->ward_name, 'payer_type' => $encounter->payer_type,
            ],
            'patient' => [
                'public_id' => $encounter->patient?->public_id,
                'medical_record_number' => $encounter->patient?->medical_record_number,
                'full_name' => $encounter->patient?->full_name,
                'date_of_birth' => $encounter->patient?->date_of_birth?->toDateString(),
                'sex' => $encounter->patient?->sex,
            ],
            'discharge' => $encounter->inpatientDischarge === null ? null : [
                'public_id' => $encounter->inpatientDischarge->public_id,
                'disposition_code' => $encounter->inpatientDischarge->disposition_code,
                'disposition_label' => $encounter->inpatientDischarge->disposition_label,
                'discharged_at' => $encounter->inpatientDischarge->discharged_at->toIso8601String(),
                'location_sequence' => $encounter->inpatientDischarge->location_sequence,
            ],
            'discharge_summary' => $this->summaryProjection($encounter),
            'coding_source' => $this->codingSourceProjection($source, $sourceVersion, $snapshot),
            'coding' => [
                'state' => $coding instanceof InpatientRmCoding ? $coding->coding_state : InpatientRmCoding::STATE_DRAFT,
                'version' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->version : 0,
                'content_digest' => $codingVersion instanceof InpatientRmCodingVersion ? $codingVersion->content_digest : hash('sha256', ''),
                'source_statements' => $this->sourceStatementProjection($sourceVersion),
                'assignments' => $this->assignmentProjection($codingVersion),
                'history' => $coding instanceof InpatientRmCoding ? $coding->versions->sortBy('version')->map(fn (InpatientRmCodingVersion $version): array => [
                    'version' => $version->version,
                    'assignments' => $this->assignmentProjection($version),
                    'actor_name' => $version->actor?->name,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])->values()->all() : [],
                'can_save_draft' => $encounter->status === Encounter::STATUS_READY_FOR_RM
                    && (! $coding instanceof InpatientRmCoding || $coding->coding_state !== InpatientRmCoding::STATE_FINAL)
                    && $actor->canCapability(Capability::RMIK_CODING_WRITE),
            ],
            'completeness' => $this->completenessProjection($encounter, $actor, $latestReview, $snapshot),
            'history' => $this->historyProjection($encounter),
            'actions' => [
                'save_coding_draft_url' => $encounter->status === Encounter::STATUS_CLOSED ? null : route('rm.rawat-inap.coding.draft', $encounter),
                'save_review_url' => $encounter->status === Encounter::STATUS_CLOSED ? null : route('rm.rawat-inap.reviews.store', $encounter),
                'signoff_url' => $encounter->status === Encounter::STATUS_CLOSED ? null : route('rm.rawat-inap.signoff', $encounter),
            ],
            'summary_addendum' => $this->summaryAddendumProjection->forEncounter($encounter, $actor),
        ]]);
    }

    /**
     * @param array{
     *   source_fingerprint:string,coding_version:int,coding_digest:string,
     *   source_version_public_id:string|null,source_content_digest:string|null,source_provenance_digest:string|null,
     *   items:list<array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null}>,
     *   blockers:list<string>
     * } $snapshot
     * @return array<string, mixed>
     */
    private function completenessProjection(Encounter $encounter, User $actor, ?InpatientRmCompletenessReview $latestReview, array $snapshot): array
    {
        $current = $latestReview !== null
            && hash_equals($latestReview->source_fingerprint, $snapshot['source_fingerprint'])
            && $latestReview->coding_version === $snapshot['coding_version']
            && hash_equals($latestReview->coding_digest, $snapshot['coding_digest']);
        $status = $encounter->status === Encounter::STATUS_CLOSED ? 'SIGNED_OFF'
            : (! $current ? 'NOT_REVIEWED' : ($snapshot['blockers'] === [] ? 'COMPLETE' : 'INCOMPLETE'));
        $items = collect($snapshot['items'])->map(fn (array $item): array => [
            'code' => $item['item_code'], 'label' => $item['label'],
            'status' => $item['is_complete'] ? 'PASS' : 'FAIL',
            'reason' => $item['is_complete'] ? null : 'Sumber wajib belum lengkap atau masih aktif.',
        ])->all();
        $byCode = collect($items)->keyBy('code');

        return [
            'status' => $status,
            'version' => $latestReview instanceof InpatientRmCompletenessReview ? $latestReview->version : 0,
            'source_fingerprint' => $snapshot['source_fingerprint'],
            'items' => $items,
            'blockers' => collect($snapshot['blockers'])->map(fn (string $code): array => [
                'code' => $code, 'label' => $byCode->get($code)['label'] ?? $code,
                'reason' => $byCode->get($code)['reason'] ?? 'Sumber wajib belum lengkap.',
            ])->all(),
            'reviewer_name' => $latestReview instanceof InpatientRmCompletenessReview ? $latestReview->reviewedBy->name : null,
            'reviewed_at' => $latestReview instanceof InpatientRmCompletenessReview ? $latestReview->reviewed_at->toIso8601String() : null,
            'signed_off_by_name' => $latestReview instanceof InpatientRmCompletenessReview ? $latestReview->signedOffBy?->name : null,
            'signed_off_at' => $latestReview instanceof InpatientRmCompletenessReview ? $latestReview->signed_off_at?->toIso8601String() : null,
            'can_save_review' => $encounter->status === Encounter::STATUS_READY_FOR_RM
                && $snapshot['coding_version'] > 0 && $actor->canCapability(Capability::RMIK_REVIEW),
            'can_signoff' => $encounter->status === Encounter::STATUS_READY_FOR_RM
                && $current
                && $latestReview->review_state === InpatientRmCompletenessReview::STATE_DRAFT
                && $snapshot['blockers'] === [] && $actor->canCapability(Capability::RMIK_COMPLETENESS_SIGNOFF),
        ];
    }

    public function saveCodingDraft(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::RMIK_CODING_WRITE);
        $actor = $request->user();
        assert($actor instanceof User);
        $this->assertOnlyKeys($request, ['expected_version', 'assignments', 'idempotency_key']);
        $payload = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:0'],
            'assignments' => ['required', 'array', 'max:41'],
            'assignments.*.source_statement_kind' => ['required', 'string', 'max:32'],
            'assignments.*.source_statement_index' => ['required', 'integer', 'min:0', 'max:20'],
            'assignments.*.source_statement_text_hash' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'assignments.*.code' => ['required', 'string', 'max:64'],
            'assignments.*.description' => ['required', 'string', 'max:255'],
            'assignments.*.kind' => ['sometimes', 'nullable', 'string', 'max:32'],
            'assignments.*.source_statement' => ['sometimes', 'nullable', 'string', 'max:500'],
            'assignments.*.public_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255'],
        ])->validate();
        try {
            $snapshot = $this->service->snapshot($encounter->fresh());
            $assignments = array_map(static fn (array $assignment): array => [
                'source_statement_kind' => $assignment['source_statement_kind'],
                'source_statement_index' => (int) $assignment['source_statement_index'],
                'source_statement_text_hash' => $assignment['source_statement_text_hash'],
                'code' => $assignment['code'],
                'description' => $assignment['description'],
            ], $payload['assignments']);
            $result = $this->service->saveCodingDraft(
                $encounter->public_id, $actor, (int) $payload['expected_version'],
                $snapshot['source_version_public_id'], $snapshot['source_content_digest'],
                $snapshot['source_provenance_digest'], $assignments,
                $payload['idempotency_key'], $request->attributes->get('request_id'),
            );
        } catch (InpatientRmDenied $denial) {
            return $this->denied($request, $denial);
        }

        return redirect()->route('rm.rawat-inap.show', $encounter)
            ->with('success', $result->replayed ? 'Coding Draft sudah tersimpan.' : 'Coding Draft tersimpan sebagai versi baru.');
    }

    public function saveReview(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::RMIK_REVIEW);

        return $this->reviewAction($request, $encounter, false);
    }

    public function signoff(Request $request, Encounter $encounter): RedirectResponse
    {
        Gate::authorize(Capability::RMIK_COMPLETENESS_SIGNOFF);

        return $this->reviewAction($request, $encounter, true);
    }

    private function reviewAction(Request $request, Encounter $encounter, bool $signoff): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $this->assertOnlyKeys($request, ['expected_version', 'source_fingerprint', 'coding_version', 'coding_digest', 'idempotency_key']);
        $payload = Validator::make($request->all(), [
            'expected_version' => ['required', 'integer', 'min:0'],
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'coding_version' => ['required', 'integer', 'min:1'],
            'coding_digest' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255'],
        ])->validate();
        try {
            $method = $signoff ? 'signoff' : 'saveReview';
            $result = $this->service->{$method}(
                $encounter->public_id, $actor, (int) $payload['expected_version'],
                $payload['source_fingerprint'], (int) $payload['coding_version'],
                $payload['coding_digest'], $payload['idempotency_key'],
                $request->attributes->get('request_id'),
            );
        } catch (InpatientRmDenied $denial) {
            return $this->denied($request, $denial);
        }

        return redirect()->route('rm.rawat-inap.show', $encounter)->with(
            'success',
            $signoff
                ? ($result->replayed ? 'Episode sudah ditutup.' : 'Coding difinalkan, review ditandatangani, dan episode ditutup.')
                : ($result->replayed ? 'Review sudah tersimpan.' : 'Snapshot kelengkapan tersimpan.'),
        );
    }

    /** @return array<string, mixed>|null */
    private function summaryProjection(Encounter $encounter): ?array
    {
        $summary = $encounter->inpatientDischargeSummary;
        if ($summary === null) {
            return null;
        }

        return [
            'public_id' => $summary->public_id, 'state' => $summary->summary_state,
            'definition_version' => $summary->definition_version, 'version' => $summary->version,
            'fields' => collect(InpatientDischargeSummary::NARRATIVE_FIELDS)
                ->mapWithKeys(fn (string $field): array => [$field => $summary->getAttribute($field)])->all(),
            'finalized_at' => $summary->finalized_at?->toIso8601String(),
        ];
    }

    /**
     * @param array{
     *   source_fingerprint:string,coding_version:int,coding_digest:string,
     *   source_version_public_id:string|null,source_content_digest:string|null,source_provenance_digest:string|null,
     *   items:list<array{item_code:string,label:string,is_blocking:bool,is_complete:bool,source_reference:string|null}>,
     *   blockers:list<string>
     * } $snapshot
     * @return array<string, mixed>|null
     */
    private function codingSourceProjection(?InpatientDischargeCodingSource $source, ?InpatientDischargeCodingSourceVersion $version, array $snapshot): ?array
    {
        if (! $source instanceof InpatientDischargeCodingSource || $version === null) {
            return null;
        }
        $statements = [[
            'source_statement_kind' => InpatientRmCodingAssignment::KIND_PRINCIPAL,
            'source_statement_index' => 0,
            'source_statement_text_hash' => hash('sha256', (string) $version->principal_diagnosis_statement),
            'source_statement_reference' => $version->principal_diagnosis_statement,
            'code_system' => InpatientRmCoding::DIAGNOSIS_CODE_SYSTEM,
        ]];
        foreach ($version->secondary_diagnosis_statements as $index => $statement) {
            $statements[] = [
                'source_statement_kind' => InpatientRmCodingAssignment::KIND_SECONDARY,
                'source_statement_index' => $index,
                'source_statement_text_hash' => hash('sha256', $statement),
                'source_statement_reference' => $statement,
                'code_system' => InpatientRmCoding::DIAGNOSIS_CODE_SYSTEM,
            ];
        }
        foreach ($version->performed_procedure_statements as $index => $statement) {
            $statements[] = [
                'source_statement_kind' => InpatientRmCodingAssignment::KIND_PROCEDURE,
                'source_statement_index' => $index,
                'source_statement_text_hash' => hash('sha256', $statement),
                'source_statement_reference' => $statement,
                'code_system' => InpatientRmCoding::PROCEDURE_CODE_SYSTEM,
            ];
        }

        return [
            'public_id' => $source->public_id, 'state' => $source->source_state,
            'definition_version' => $source->definition_version, 'version' => $source->version,
            'version_public_id' => $version->public_id,
            'source_content_digest' => $snapshot['source_content_digest'],
            'source_provenance_digest' => $snapshot['source_provenance_digest'],
            'procedure_attestation' => $version->procedure_attestation,
            'no_procedure' => $version->procedure_attestation === InpatientDischargeCodingSource::ATTESTATION_NONE,
            'principal_diagnosis_statement' => $version->principal_diagnosis_statement,
            'secondary_diagnosis_statements' => $version->secondary_diagnosis_statements,
            'performed_procedure_statements' => $version->performed_procedure_statements,
            'statements' => $statements,
            'profile' => InpatientRmCoding::PROFILE,
            'terminology_lookup' => false,
            'automatic_clinical_decision' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function assignmentProjection(?InpatientRmCodingVersion $version): array
    {
        if ($version === null) {
            return [];
        }

        return array_values($version->assignments->where('is_no_procedure_attestation', false)->map(fn (InpatientRmCodingAssignment $assignment): array => [
            'public_id' => $assignment->public_id,
            'kind' => $assignment->source_statement_kind,
            'source_statement_kind' => $assignment->source_statement_kind,
            'source_statement_index' => $assignment->source_statement_index,
            'source_statement_text_hash' => $assignment->source_statement_text_hash,
            'source_statement_reference' => $assignment->source_statement_reference,
            'code_system' => $assignment->code_system, 'profile' => $assignment->profile,
            'code' => $assignment->normalized_code, 'description' => $assignment->display,
            'source_statement' => $assignment->source_statement_reference,
            'ordinal' => $assignment->ordinal,
            'is_no_procedure_attestation' => $assignment->is_no_procedure_attestation,
        ])->all());
    }

    /** @return list<array{kind:string,index:int,text:string,text_hash:string}> */
    private function sourceStatementProjection(?InpatientDischargeCodingSourceVersion $version): array
    {
        if ($version === null) {
            return [];
        }
        $rows = [[
            'kind' => InpatientRmCodingAssignment::KIND_PRINCIPAL,
            'index' => 0, 'text' => (string) $version->principal_diagnosis_statement,
            'text_hash' => hash('sha256', (string) $version->principal_diagnosis_statement),
        ]];
        foreach ($version->secondary_diagnosis_statements as $index => $statement) {
            $rows[] = ['kind' => InpatientRmCodingAssignment::KIND_SECONDARY, 'index' => $index, 'text' => $statement, 'text_hash' => hash('sha256', $statement)];
        }
        foreach ($version->performed_procedure_statements as $index => $statement) {
            $rows[] = ['kind' => InpatientRmCodingAssignment::KIND_PROCEDURE, 'index' => $index, 'text' => $statement, 'text_hash' => hash('sha256', $statement)];
        }

        return $rows;
    }

    /** @return list<array{event:string,actor_name:?string,occurred_at:?string,detail:?string}> */
    private function historyProjection(Encounter $encounter): array
    {
        $events = [];
        $coding = $encounter->inpatientRmCoding()->first();
        foreach ($coding instanceof InpatientRmCoding ? $coding->versions : [] as $version) {
            $events[] = [
                'event' => $version->coding_state === InpatientRmCoding::STATE_FINAL ? 'CODING_FINALIZED' : 'CODING_DRAFT_SAVED',
                'actor_name' => $version->actor->name,
                'occurred_at' => $version->created_at?->toIso8601String(),
                'detail' => 'Coding version '.$version->version,
            ];
        }
        foreach ($encounter->inpatientRmCompletenessReviews as $review) {
            $events[] = [
                'event' => $review->review_state === InpatientRmCompletenessReview::STATE_SIGNED_OFF ? 'EPISODE_SIGNED_OFF' : 'COMPLETENESS_REVIEW_SAVED',
                'actor_name' => $review->signed_off_by_user_id !== null
                    ? $review->signedOffBy->name
                    : $review->reviewedBy->name,
                'occurred_at' => ($review->signed_off_at ?? $review->reviewed_at)->toIso8601String(),
                'detail' => 'Review version '.$review->version,
            ];
        }

        return array_values(collect($events)->sortBy('occurred_at')->all());
    }

    /** @param list<string> $allowed */
    private function assertOnlyKeys(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['inpatient_rm' => 'Permintaan memuat kolom yang tidak diizinkan.']);
        }
    }

    private function denied(Request $request, InpatientRmDenied $denial): RedirectResponse
    {
        if ($request->header('X-Inertia') === 'true') {
            return back()->withErrors(['inpatient_rm' => $denial->getMessage()]);
        }
        abort($denial->status, $denial->getMessage());
    }
}
