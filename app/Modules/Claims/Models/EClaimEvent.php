<?php

namespace App\Modules\Claims\Models;

use App\Modules\Claims\Enums\EClaimAction;
use App\Modules\Teaching\Models\Assignment;
use App\Support\CanonicalJson;
use App\Support\Models\HasPublicUlid;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $request_key
 * @property int $e_claim_case_id
 * @property int $sequence_number
 * @property EClaimAction $action
 * @property string $method
 * @property array<string, mixed> $request_payload
 * @property array<string, mixed> $response_payload
 * @property string $request_hash
 * @property string $response_hash
 * @property int $response_code
 * @property string $transport_state
 * @property int $actor_assignment_id
 * @property CarbonImmutable $recorded_at
 */
class EClaimEvent extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'e_claim_case_id',
        'sequence_number',
        'action',
        'method',
        'request_payload',
        'response_payload',
        'request_hash',
        'response_hash',
        'response_code',
        'transport_state',
        'actor_user_id',
        'actor_assignment_id',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $case = EClaimCase::query()->find($event->e_claim_case_id);
            $actor = Assignment::query()->active()->find($event->actor_assignment_id);

            if (! $case
                || ! $actor
                || $event->actor_user_id !== $actor->user_id
                || $actor->session_id !== $case->session_id
                || $actor->patient_id !== $case->patient_id
                || $actor->encounter_id !== $case->encounter_id
                || $event->method !== $event->action->method()
                || $event->transport_state !== 'NOT_SENT'
                || ! hash_equals(hash('sha256', CanonicalJson::encode($event->request_payload)), $event->request_hash)
                || ! hash_equals(hash('sha256', CanonicalJson::encode($event->response_payload)), $event->response_hash)) {
                throw new DomainException('An E-Klaim event must preserve its local request, response, actor, hashes, and never-sent boundary.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('E-Klaim simulation events are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('E-Klaim simulation events are append-only.');
        });
    }

    /** @return BelongsTo<EClaimCase, $this> */
    public function claimCase(): BelongsTo
    {
        return $this->belongsTo(EClaimCase::class, 'e_claim_case_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function actorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'actor_assignment_id');
    }

    protected function casts(): array
    {
        return [
            'action' => EClaimAction::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'response_code' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
