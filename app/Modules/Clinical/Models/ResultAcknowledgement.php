<?php

namespace App\Modules\Clinical\Models;

use App\Models\User;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $diagnostic_result_id
 * @property int $actor_assignment_id
 * @property string $outcome
 * @property string|null $comment
 * @property CarbonImmutable $acknowledged_at
 * @property-read DiagnosticResult $diagnosticResult
 */
class ResultAcknowledgement extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'diagnostic_result_id',
        'actor_user_id',
        'actor_assignment_id',
        'outcome',
        'comment',
        'acknowledged_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $acknowledgement): void {
            $result = DiagnosticResult::query()->with('serviceRequest')->find($acknowledgement->diagnostic_result_id);
            $actor = Assignment::query()->active()->find($acknowledgement->actor_assignment_id);

            if (! $result
                || ! $actor
                || $acknowledgement->actor_user_id !== $actor->user_id
                || $actor->session_id !== $result->serviceRequest->session_id
                || $actor->patient_id !== $result->serviceRequest->patient_id
                || $actor->encounter_id !== $result->serviceRequest->encounter_id
                || ! $actor->hasCapability(Capability::MedicalAssessmentWrite)
                || DiagnosticResult::query()
                    ->where('service_request_id', $result->service_request_id)
                    ->max('version_number') !== $result->version_number) {
                throw new DomainException('A result acknowledgement must reference the current result and an authorized medical assignment in the exact case.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Result acknowledgements are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Result acknowledgements are append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<DiagnosticResult, $this> */
    public function diagnosticResult(): BelongsTo
    {
        return $this->belongsTo(DiagnosticResult::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function actorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'actor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
        ];
    }
}
