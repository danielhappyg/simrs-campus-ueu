<?php

namespace App\Models;

use App\Support\Models\HasPublicUlid;
use App\Support\Models\UsesSchemaQualifiedTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $amendment_request_id
 * @property int $encounter_id
 * @property int $original_document_id
 * @property int $original_document_version
 * @property int $author_user_id
 * @property int|null $finalized_by_user_id
 * @property string $addendum_state
 * @property string $definition_version
 * @property int $version
 * @property array{addendum_text: string} $fields
 * @property Carbon|null $finalized_at
 * @property Carbon|null $created_at
 */
class OutpatientClinicalDocumentAddendum extends Model
{
    use HasPublicUlid, UsesSchemaQualifiedTable;

    protected $table = 'outpatient_clinical_document_addenda';

    public const DEFINITION_VERSION = 'RJ-ADDENDUM-v1';

    public const STATE_DRAFT = 'DRAFT';

    public const STATE_FINAL = 'FINAL';

    protected $fillable = [
        'amendment_request_id', 'encounter_id', 'original_document_id',
        'original_document_version', 'author_user_id', 'finalized_by_user_id',
        'addendum_state', 'definition_version', 'version', 'fields', 'finalized_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $addendum): void {
            $request = OutpatientPostClosureAmendmentRequest::query()
                ->whereKey($addendum->amendment_request_id)
                ->first();

            if (! $request instanceof OutpatientPostClosureAmendmentRequest
                || $request->request_state !== OutpatientPostClosureAmendmentRequest::STATE_APPROVED
                || $request->encounter_id !== $addendum->encounter_id
                || $request->original_document_id !== $addendum->original_document_id
                || $request->original_document_version !== $addendum->original_document_version
                || $request->requested_by_user_id !== $addendum->author_user_id
                || $request->decided_by_user_id === null
                || $request->decided_by_user_id === $addendum->author_user_id
                || $addendum->addendum_state !== self::STATE_DRAFT
                || $addendum->definition_version !== self::DEFINITION_VERSION
                || $addendum->version !== 1
                || $addendum->finalized_by_user_id !== null
                || $addendum->finalized_at !== null) {
                throw new LogicException('New outpatient addenda must start as a clean attributable DRAFT version 1 for an approved request.');
            }

            self::assertValidFields($addendum->fields);
        });
        static::updating(static function (self $addendum): void {
            if ($addendum->getOriginal('addendum_state') === self::STATE_FINAL) {
                throw new LogicException('Final outpatient addenda are immutable.');
            }

            foreach ([
                'public_id', 'amendment_request_id', 'encounter_id', 'original_document_id',
                'original_document_version', 'author_user_id', 'definition_version',
            ] as $attribute) {
                if ($addendum->isDirty($attribute)) {
                    throw new LogicException('Outpatient addendum provenance is immutable.');
                }
            }

            $priorVersion = (int) $addendum->getOriginal('version');
            if ($addendum->version !== $priorVersion + 1) {
                throw new LogicException('Outpatient addendum transitions must increment the version exactly once.');
            }

            self::assertValidFields($addendum->fields);

            if ($addendum->addendum_state === self::STATE_DRAFT) {
                if ($addendum->finalized_by_user_id !== null
                    || $addendum->finalized_at !== null
                    || $addendum->isDirty('finalized_by_user_id')
                    || $addendum->isDirty('finalized_at')) {
                    throw new LogicException('Draft outpatient addenda cannot contain finalization evidence.');
                }

                return;
            }

            if ($addendum->addendum_state !== self::STATE_FINAL
                || ! OutpatientPostClosureAmendmentRequest::allowsAggregateFinalization(
                    (int) $addendum->amendment_request_id,
                    (int) $addendum->id,
                )) {
                throw new LogicException('Outpatient addenda may become FINAL only inside the guarded aggregate finalization.');
            }

            $request = OutpatientPostClosureAmendmentRequest::query()
                ->whereKey($addendum->amendment_request_id)
                ->first();
            if (! $request instanceof OutpatientPostClosureAmendmentRequest
                || $request->request_state !== OutpatientPostClosureAmendmentRequest::STATE_APPROVED
                || $request->requested_by_user_id !== $addendum->author_user_id
                || $request->decided_by_user_id === null
                || $request->decided_by_user_id === $addendum->author_user_id
                || $addendum->finalized_by_user_id !== $addendum->author_user_id
                || $addendum->finalized_at === null
                || $addendum->isDirty('fields')) {
                throw new LogicException('Final outpatient addenda require the approved requester as unchanged author and finalizer.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Outpatient addenda cannot be deleted by ordinary workflow.');
        });
    }

    /** @return BelongsTo<OutpatientPostClosureAmendmentRequest, $this> */
    public function amendmentRequest(): BelongsTo
    {
        return $this->belongsTo(OutpatientPostClosureAmendmentRequest::class, 'amendment_request_id');
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    /** @return HasMany<OutpatientClinicalDocumentAddendumVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OutpatientClinicalDocumentAddendumVersion::class, 'addendum_id');
    }

    protected function casts(): array
    {
        return [
            'original_document_version' => 'integer',
            'version' => 'integer',
            'fields' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    private static function assertValidFields(mixed $fields): void
    {
        if (! is_array($fields)
            || array_keys($fields) !== ['addendum_text']
            || ! is_string($fields['addendum_text'])
            || trim($fields['addendum_text']) === ''
            || mb_strlen($fields['addendum_text']) > 5000) {
            throw new LogicException('Outpatient addendum fields must contain exactly one bounded addendum_text value.');
        }
    }
}
