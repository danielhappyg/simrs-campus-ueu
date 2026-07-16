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
 * @property int $pharmacy_intervention_id
 * @property int $author_assignment_id
 * @property string $message_type
 * @property string|null $response_action
 * @property string $message_text
 * @property int|null $replacement_medication_request_id
 * @property CarbonImmutable $authored_at
 */
class PharmacyInterventionMessage extends Model
{
    use HasPublicUlid;

    protected $fillable = [
        'request_key',
        'pharmacy_intervention_id',
        'author_user_id',
        'author_assignment_id',
        'message_type',
        'response_action',
        'message_text',
        'replacement_medication_request_id',
        'authored_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $intervention = PharmacyIntervention::query()->with('medicationRequest')->find($message->pharmacy_intervention_id);
            $actor = Assignment::query()->active()->find($message->author_assignment_id);
            $replacement = $message->replacement_medication_request_id === null
                ? null
                : MedicationRequest::query()->find($message->replacement_medication_request_id);

            if (! $intervention
                || ! $actor
                || $message->author_user_id !== $actor->user_id
                || $actor->session_id !== $intervention->session_id
                || $actor->patient_id !== $intervention->patient_id
                || $actor->encounter_id !== $intervention->encounter_id
                || ($replacement && ($replacement->session_id !== $intervention->session_id
                    || $replacement->patient_id !== $intervention->patient_id
                    || $replacement->encounter_id !== $intervention->encounter_id))
                || ($message->replacement_medication_request_id !== null && ! $replacement)) {
                throw new DomainException('A pharmacy intervention message must match an authorized author and exact intervention context.');
            }

            $authorized = match ($message->message_type) {
                'PHARMACY_QUERY', 'PHARMACY_RESOLUTION' => $actor->hasCapability(Capability::PharmacyReview),
                'PRESCRIBER_RESPONSE' => $actor->getKey() === $intervention->medicationRequest->requester_assignment_id
                    && $actor->hasCapability(Capability::PrescriptionWrite),
                default => false,
            };

            if (! $authorized) {
                throw new DomainException('The assignment cannot author this intervention message type.');
            }
        });

        static::updating(function (): never {
            throw new DomainException('Pharmacy intervention messages are immutable.');
        });

        static::deleting(function (): never {
            throw new DomainException('Pharmacy intervention messages are append-only.');
        });
    }

    /** @return BelongsTo<PharmacyIntervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(PharmacyIntervention::class, 'pharmacy_intervention_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** @return BelongsTo<Assignment, $this> */
    public function authorAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'author_assignment_id');
    }

    /** @return BelongsTo<MedicationRequest, $this> */
    public function replacementMedicationRequest(): BelongsTo
    {
        return $this->belongsTo(MedicationRequest::class, 'replacement_medication_request_id');
    }

    protected function casts(): array
    {
        return [
            'authored_at' => 'immutable_datetime',
        ];
    }
}
