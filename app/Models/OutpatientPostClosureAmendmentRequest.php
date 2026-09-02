<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $encounter_id
 * @property int $original_document_id
 * @property int $original_document_version
 * @property int $requested_by_user_id
 * @property int|null $decided_by_user_id
 * @property string $reason_code
 * @property string|null $note
 * @property string $request_state
 * @property int $version
 * @property string|null $decision_note
 * @property Carbon|null $decided_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
class OutpatientPostClosureAmendmentRequest extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    public const REASON_CLINICAL_CORRECTION = 'CLINICAL_CORRECTION';

    public const REASON_MISSING_INFORMATION = 'MISSING_INFORMATION';

    public const REASON_WRONG_ENTRY = 'WRONG_ENTRY';

    public const REASON_OTHER = 'OTHER';

    /** @var list<string> */
    public const REASON_CODES = [
        self::REASON_CLINICAL_CORRECTION,
        self::REASON_MISSING_INFORMATION,
        self::REASON_WRONG_ENTRY,
        self::REASON_OTHER,
    ];

    /** @var array<string, string> */
    public const REASON_LABELS = [
        self::REASON_CLINICAL_CORRECTION => 'Koreksi klinis',
        self::REASON_MISSING_INFORMATION => 'Informasi belum lengkap',
        self::REASON_WRONG_ENTRY => 'Entri tidak tepat',
        self::REASON_OTHER => 'Lainnya',
    ];

    public const STATE_SUBMITTED = 'SUBMITTED';

    public const STATE_APPROVED = 'APPROVED';

    public const STATE_DENIED = 'DENIED';

    public const STATE_CONSUMED = 'CONSUMED';

    /** @var array{request_id: int, addendum_id: int}|null */
    private static ?array $aggregateFinalization = null;

    protected $fillable = [
        'encounter_id', 'original_document_id', 'original_document_version',
        'requested_by_user_id', 'decided_by_user_id', 'reason_code', 'note',
        'request_state', 'version', 'decision_note', 'decided_at', 'consumed_at',
        'request_correlation_id',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $request): void {
            if ($request->request_state !== self::STATE_SUBMITTED
                || $request->version !== 1
                || $request->decided_by_user_id !== null
                || $request->decision_note !== null
                || $request->decided_at !== null
                || $request->consumed_at !== null) {
                throw new LogicException('New outpatient amendment requests must start as a clean SUBMITTED version 1 record.');
            }
        });
        static::updating(static function (self $request): void {
            foreach ([
                'public_id', 'encounter_id', 'original_document_id', 'original_document_version',
                'requested_by_user_id', 'reason_code', 'note', 'request_correlation_id',
            ] as $attribute) {
                if ($request->isDirty($attribute)) {
                    throw new LogicException('Outpatient amendment request identity and reason evidence are immutable.');
                }
            }

            $priorState = $request->getOriginal('request_state');
            $priorVersion = (int) $request->getOriginal('version');
            if ($request->version !== $priorVersion + 1) {
                throw new LogicException('Outpatient amendment request transitions must increment the version exactly once.');
            }

            if ($priorState === self::STATE_SUBMITTED) {
                if (! in_array($request->request_state, [self::STATE_APPROVED, self::STATE_DENIED], true)
                    || $request->decided_by_user_id === null
                    || $request->decided_by_user_id === $request->requested_by_user_id
                    || $request->decided_at === null
                    || $request->consumed_at !== null
                    || ($request->request_state === self::STATE_DENIED && trim((string) $request->decision_note) === '')) {
                    throw new LogicException('SUBMITTED outpatient amendment requests require an attributable different-physician decision.');
                }

                return;
            }

            if ($priorState === self::STATE_APPROVED) {
                foreach (['decided_by_user_id', 'decision_note', 'decided_at'] as $attribute) {
                    if ($request->isDirty($attribute)) {
                        throw new LogicException('Approved outpatient amendment decision evidence is immutable.');
                    }
                }
                if ($request->request_state !== self::STATE_CONSUMED
                    || $request->consumed_at === null
                    || ! self::allowsAggregateFinalization((int) $request->id, self::$aggregateFinalization['addendum_id'] ?? 0)) {
                    throw new LogicException('APPROVED outpatient amendment requests may only transition to CONSUMED.');
                }

                $addendum = OutpatientClinicalDocumentAddendum::query()
                    ->whereKey(self::$aggregateFinalization['addendum_id'])
                    ->where('amendment_request_id', $request->id)
                    ->first();
                if (! $addendum instanceof OutpatientClinicalDocumentAddendum
                    || $addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_FINAL
                    || $addendum->author_user_id !== $request->requested_by_user_id
                    || $addendum->finalized_by_user_id !== $request->requested_by_user_id
                    || $addendum->finalized_at === null
                    || ! $addendum->finalized_at->equalTo($request->consumed_at)) {
                    throw new LogicException('CONSUMED outpatient amendment requests require their matching attributable FINAL addendum.');
                }

                return;
            }

            if (in_array($priorState, [self::STATE_DENIED, self::STATE_CONSUMED], true)) {
                throw new LogicException('Terminal outpatient amendment requests are immutable.');
            }

            throw new LogicException('Unknown outpatient amendment request transition.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient amendment requests cannot be deleted by ordinary workflow.');
        });
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $transition
     * @return TResult
     */
    public static function withinAggregateFinalization(
        self $request,
        OutpatientClinicalDocumentAddendum $addendum,
        callable $transition,
    ): mixed {
        if (self::$aggregateFinalization !== null
            || ! $request->exists
            || ! $addendum->exists
            || $addendum->amendment_request_id !== $request->id
            || $request->request_state !== self::STATE_APPROVED
            || $addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_DRAFT) {
            throw new LogicException('Outpatient amendment aggregate finalization context is invalid.');
        }

        self::$aggregateFinalization = [
            'request_id' => (int) $request->id,
            'addendum_id' => (int) $addendum->id,
        ];

        try {
            return DB::transaction(function () use ($request, $addendum, $transition): mixed {
                $result = $transition();
                $request->refresh();
                $addendum->refresh();

                if ($request->request_state !== self::STATE_CONSUMED
                    || $addendum->addendum_state !== OutpatientClinicalDocumentAddendum::STATE_FINAL
                    || $request->consumed_at === null
                    || $addendum->finalized_at === null
                    || ! $request->consumed_at->equalTo($addendum->finalized_at)) {
                    throw new LogicException('Outpatient amendment aggregate finalization did not commit a matching FINAL addendum and CONSUMED request.');
                }

                return $result;
            });
        } finally {
            self::$aggregateFinalization = null;
        }
    }

    public static function allowsAggregateFinalization(int $requestId, int $addendumId): bool
    {
        return self::$aggregateFinalization === [
            'request_id' => $requestId,
            'addendum_id' => $addendumId,
        ];
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<OutpatientClinicalDocument, $this> */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(OutpatientClinicalDocument::class, 'original_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return HasOne<OutpatientClinicalDocumentAddendum, $this> */
    public function addendum(): HasOne
    {
        return $this->hasOne(OutpatientClinicalDocumentAddendum::class, 'amendment_request_id');
    }

    /** @return HasMany<OutpatientRmAmendmentReview, $this> */
    public function renewedReviews(): HasMany
    {
        return $this->hasMany(OutpatientRmAmendmentReview::class, 'amendment_request_id');
    }

    protected function casts(): array
    {
        return [
            'original_document_version' => 'integer',
            'version' => 'integer',
            'decided_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
