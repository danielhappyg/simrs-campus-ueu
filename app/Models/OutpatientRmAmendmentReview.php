<?php

namespace App\Models;

use App\Support\Authorization\Capability;
use App\Support\Clinical\OutpatientAmendmentActorPolicy;
use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $amendment_request_id
 * @property int $addendum_id
 * @property int $encounter_id
 * @property int $baseline_review_id
 * @property int $reviewed_by_user_id
 * @property int|null $signed_off_by_user_id
 * @property string $definition_version
 * @property int $version
 * @property string $source_fingerprint
 * @property string $review_state
 * @property CarbonInterface $reviewed_at
 * @property CarbonInterface|null $signed_off_at
 * @property CarbonInterface|null $created_at
 */
class OutpatientRmAmendmentReview extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const DEFINITION_VERSION = 'OUTPATIENT_RM_AMENDMENT_REVIEW_V1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_SIGNED_OFF = 'SIGNED_OFF';

    /** @var array<string, string> */
    public const ITEM_LABELS = [
        'BASELINE_SIGNOFF' => 'Sign-off kelengkapan RM awal tersedia',
        'FINAL_ADDENDUM' => 'Addendum Final tersedia dan tercakup dalam sumber',
        'CURRENT_SOURCE_REFERENCE' => 'Dokumen sumber Final tetap sesuai dengan versi yang dirujuk',
        'NO_ACTIVE_LAB_ORDERS' => 'Tidak ada order laboratorium aktif',
    ];

    /**
     * @var array{
     *   amendment_request_id: int,
     *   addendum_id: int,
     *   encounter_id: int,
     *   baseline_review_id: int,
     *   actor_user_id: int,
     *   definition_version: string,
     *   version: int,
     *   source_fingerprint: string,
     *   review_state: string,
     *   occurred_at: CarbonInterface,
     *   items: array<string, array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>,
     *   review_id: int|null
     * }|null
     */
    private static ?array $aggregateCreation = null;

    protected $fillable = [
        'amendment_request_id', 'addendum_id', 'encounter_id', 'baseline_review_id',
        'reviewed_by_user_id', 'signed_off_by_user_id', 'definition_version',
        'version', 'source_fingerprint', 'review_state', 'reviewed_at', 'signed_off_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $review): void {
            $context = self::$aggregateCreation;
            $signed = $review->review_state === self::STATE_SIGNED_OFF;

            if ($context === null
                || $review->amendment_request_id !== $context['amendment_request_id']
                || $review->addendum_id !== $context['addendum_id']
                || $review->encounter_id !== $context['encounter_id']
                || $review->baseline_review_id !== $context['baseline_review_id']
                || $review->reviewed_by_user_id !== $context['actor_user_id']
                || $review->signed_off_by_user_id !== ($signed ? $context['actor_user_id'] : null)
                || $review->definition_version !== $context['definition_version']
                || $review->version !== $context['version']
                || $review->source_fingerprint !== $context['source_fingerprint']
                || $review->review_state !== $context['review_state']
                || ! $review->reviewed_at->equalTo($context['occurred_at'])
                || ($signed && (! $review->signed_off_at instanceof CarbonInterface || ! $review->signed_off_at->equalTo($context['occurred_at'])))
                || (! $signed && $review->signed_off_at !== null)) {
                throw new LogicException('Renewed RMIK reviews may only be created as exact guarded aggregate snapshots.');
            }
        });
        static::created(static function (self $review): void {
            if (self::$aggregateCreation !== null) {
                self::$aggregateCreation['review_id'] = (int) $review->id;
            }
        });
        static::updating(static function (): never {
            throw new LogicException('Outpatient amendment reviews are immutable snapshots.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient amendment reviews cannot be deleted by ordinary workflow.');
        });
    }

    /**
     * @param  list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>  $items
     * @param  callable(): self  $creation
     */
    public static function withinAggregateCreation(
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        OutpatientClinicalDocumentAddendum $addendum,
        OutpatientRmCompletenessReview $baseline,
        User $actor,
        int $version,
        string $state,
        string $sourceFingerprint,
        CarbonInterface $occurredAt,
        array $items,
        callable $creation,
    ): self {
        if (self::$aggregateCreation !== null) {
            throw new LogicException('Nested renewed RMIK review creation is not allowed.');
        }

        self::assertAggregateCreation(
            $encounter,
            $request,
            $addendum,
            $baseline,
            $actor,
            $version,
            $state,
            $sourceFingerprint,
            $items,
        );

        /** @var array<string, array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}> $itemsByCode */
        $itemsByCode = [];
        foreach ($items as $item) {
            $itemsByCode[$item['item_code']] = $item;
        }

        self::$aggregateCreation = [
            'amendment_request_id' => (int) $request->id,
            'addendum_id' => (int) $addendum->id,
            'encounter_id' => (int) $encounter->id,
            'baseline_review_id' => (int) $baseline->id,
            'actor_user_id' => (int) $actor->id,
            'definition_version' => self::DEFINITION_VERSION,
            'version' => $version,
            'source_fingerprint' => $sourceFingerprint,
            'review_state' => $state,
            'occurred_at' => $occurredAt,
            'items' => $itemsByCode,
            'review_id' => null,
        ];

        try {
            return DB::transaction(function () use ($creation): self {
                $review = $creation();
                $context = self::$aggregateCreation;
                if ($context === null
                    || ! $review->exists
                    || $review->id !== $context['review_id']
                    || $review->items()->count() !== count(self::ITEM_LABELS)) {
                    throw new LogicException('Guarded renewed RMIK review creation did not produce the exact snapshot aggregate.');
                }

                return $review;
            });
        } finally {
            self::$aggregateCreation = null;
        }
    }

    public static function assertExpectedItemCreation(OutpatientRmAmendmentReviewItem $item): void
    {
        $context = self::$aggregateCreation;
        $expected = $context === null ? null : ($context['items'][$item->item_code] ?? null);
        if ($context === null
            || $context['review_id'] === null
            || $item->review_id !== $context['review_id']
            || ! is_array($expected)
            || $item->label !== $expected['label']
            || (bool) $item->is_blocking !== $expected['is_blocking']
            || (bool) $item->is_complete !== $expected['is_complete']
            || $item->source_reference !== $expected['source_reference']) {
            throw new LogicException('Renewed RMIK review items may only be created inside their exact guarded snapshot.');
        }
    }

    /**
     * @param  list<array{item_code: string, label: string, is_blocking: bool, is_complete: bool, source_reference: string|null}>  $items
     */
    private static function assertAggregateCreation(
        Encounter $encounter,
        OutpatientPostClosureAmendmentRequest $request,
        OutpatientClinicalDocumentAddendum $addendum,
        OutpatientRmCompletenessReview $baseline,
        User $actor,
        int $version,
        string $state,
        string $sourceFingerprint,
        array $items,
    ): void {
        $signed = $state === self::STATE_SIGNED_OFF;
        $latest = self::query()
            ->where('amendment_request_id', $request->id)
            ->orderByDesc('version')
            ->first();
        $expectedVersion = $latest instanceof self ? $latest->version + 1 : 1;
        $originalPublicId = $request->originalDocument()->value('public_id');

        if (! $encounter->exists
            || ! $request->exists
            || ! $addendum->exists
            || ! $baseline->exists
            || ! $actor->exists
            || $request->request_state !== OutpatientPostClosureAmendmentRequest::STATE_CONSUMED
            || $request->encounter_id !== $encounter->id
            || $addendum->amendment_request_id !== $request->id
            || $addendum->encounter_id !== $encounter->id
            || $addendum->original_document_id !== $request->original_document_id
            || $addendum->original_document_version !== $request->original_document_version
            || $addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_FINAL
            || $baseline->encounter_id !== $encounter->id
            || $baseline->review_state !== OutpatientRmCompletenessReview::STATE_SIGNED_OFF
            || $baseline->id !== OutpatientRmCompletenessReview::query()
                ->where('encounter_id', $encounter->id)
                ->where('review_state', OutpatientRmCompletenessReview::STATE_SIGNED_OFF)
                ->orderByDesc('version')
                ->value('id')
            || ! in_array($state, [self::STATE_DRAFT, self::STATE_SIGNED_OFF], true)
            || $version !== $expectedVersion
            || ($latest instanceof self && $latest->review_state === self::STATE_SIGNED_OFF)
            || ($signed && (! $latest instanceof self || $latest->review_state !== self::STATE_DRAFT))
            || preg_match('/\A[a-f0-9]{64}\z/', $sourceFingerprint) !== 1
            || ! is_string($originalPublicId)) {
            throw new LogicException('Renewed RMIK review aggregate provenance is invalid.');
        }

        app(OutpatientAmendmentActorPolicy::class)->authorizeRmik(
            $actor,
            $signed ? Capability::RMIK_COMPLETENESS_SIGNOFF : Capability::RMIK_REVIEW,
        );

        $expectedSources = [
            'BASELINE_SIGNOFF' => $baseline->public_id,
            'FINAL_ADDENDUM' => $addendum->public_id,
            'CURRENT_SOURCE_REFERENCE' => $originalPublicId,
            'NO_ACTIVE_LAB_ORDERS' => null,
        ];
        if (count($items) !== count(self::ITEM_LABELS)
            || array_column($items, 'item_code') !== array_keys(self::ITEM_LABELS)) {
            throw new LogicException('Renewed RMIK review checklist evidence is incomplete or unordered.');
        }
        foreach ($items as $item) {
            $code = $item['item_code'];
            if ($item['label'] !== self::ITEM_LABELS[$code]
                || $item['is_blocking'] !== true
                || $item['source_reference'] !== $expectedSources[$code]
                || (in_array($code, ['BASELINE_SIGNOFF', 'FINAL_ADDENDUM', 'CURRENT_SOURCE_REFERENCE'], true) && $item['is_complete'] !== true)
                || ($signed && $item['is_complete'] !== true)) {
                throw new LogicException('Renewed RMIK review checklist evidence does not match its aggregate sources.');
            }
        }
    }

    /** @return BelongsTo<OutpatientPostClosureAmendmentRequest, $this> */
    public function amendmentRequest(): BelongsTo
    {
        return $this->belongsTo(OutpatientPostClosureAmendmentRequest::class, 'amendment_request_id');
    }

    /** @return BelongsTo<OutpatientClinicalDocumentAddendum, $this> */
    public function addendum(): BelongsTo
    {
        return $this->belongsTo(OutpatientClinicalDocumentAddendum::class, 'addendum_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<OutpatientRmCompletenessReview, $this> */
    public function baselineReview(): BelongsTo
    {
        return $this->belongsTo(OutpatientRmCompletenessReview::class, 'baseline_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by_user_id');
    }

    /** @return HasMany<OutpatientRmAmendmentReviewItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OutpatientRmAmendmentReviewItem::class, 'review_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'reviewed_at' => 'datetime',
            'signed_off_at' => 'datetime',
        ];
    }
}
