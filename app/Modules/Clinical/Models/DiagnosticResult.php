<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Clinical\Enums\DiagnosticResultStatus;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $service_request_id
 * @property string $public_id
 * @property string $request_key
 * @property int $version_number
 * @property DiagnosticResultStatus $status
 * @property string $report_code
 * @property string $report_display
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property int $performer_assignment_id
 * @property CarbonImmutable $effective_at
 * @property CarbonImmutable $issued_at
 * @property int|null $supersedes_result_id
 * @property-read ServiceRequest $serviceRequest
 */
class DiagnosticResult extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'service_request_id',
        'version_number',
        'status',
        'report_code',
        'report_display',
        'content',
        'content_hash',
        'performer_user_id',
        'performer_assignment_id',
        'effective_at',
        'issued_at',
        'supersedes_result_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $result): void {
            $request = ServiceRequest::query()->find($result->service_request_id);
            $actor = Assignment::query()->active()->find($result->performer_assignment_id);
            $superseded = $result->supersedes_result_id === null
                ? null
                : self::query()->find($result->supersedes_result_id);
            $current = self::query()
                ->where('service_request_id', $result->service_request_id)
                ->orderByDesc('version_number')
                ->first();

            if (! $request
                || ! $actor
                || $result->performer_user_id !== $actor->user_id
                || $actor->session_id !== $request->session_id
                || ! self::actorCanRelease($actor, $request)
                || ($superseded && $superseded->service_request_id !== $request->getKey())
                || ($result->supersedes_result_id !== null && ! $superseded)) {
                throw new DomainException('A diagnostic result must match its request, performer authority, version lineage, and case context.');
            }

            $calculatedHash = hash('sha256', CanonicalJson::encode($result->content));

            if (! hash_equals($calculatedHash, $result->content_hash)) {
                throw new DomainException('Diagnostic result content does not match its integrity hash.');
            }

            if ($current === null
                && ($result->version_number !== 1
                    || $result->status !== DiagnosticResultStatus::Final
                    || $result->supersedes_result_id !== null)) {
                throw new DomainException('The first diagnostic result must be version one with final status and no predecessor.');
            }

            if ($current !== null
                && ($result->version_number !== $current->version_number + 1
                    || $result->status !== DiagnosticResultStatus::Corrected
                    || $result->supersedes_result_id !== $current->getKey())) {
                throw new DomainException('A successor diagnostic result must be the next explicit correction of the current version.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Diagnostic result versions are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Diagnostic result versions are immutable and cannot be deleted.');
        });
    }

    private static function actorCanRelease(Assignment $actor, ServiceRequest $request): bool
    {
        $exactCase = $actor->patient_id === $request->patient_id
            && $actor->encounter_id === $request->encounter_id
            && $actor->hasCapability(Capability::SupervisionReview);
        $sessionFacilitator = $actor->patient_id === null
            && $actor->encounter_id === null
            && $actor->hasCapability(Capability::SessionFacilitate);

        return $exactCase || $sessionFacilitator;
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performer_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function performerAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'performer_assignment_id');
    }

    /** @return BelongsTo<DiagnosticResult, $this> */
    public function supersedesResult(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_result_id');
    }

    /** @return HasMany<ResultAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(ResultAcknowledgement::class);
    }

    protected function casts(): array
    {
        return [
            'status' => DiagnosticResultStatus::class,
            'content' => 'array',
            'effective_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
        ];
    }
}
