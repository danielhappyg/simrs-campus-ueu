<?php

namespace App\Support\Audit;

use App\Models\User;

final class AuditActorAttribution
{
    public const TYPE_USER = 'USER';

    public const TYPE_SERVICE = 'SERVICE';

    public const SYNTHETIC_RESET_SERVICE = 'synthetic-reset-service';

    public const REBUILD_ADMIN_SERVICE = 'rebuild-admin-role-reconciler';

    public const TEACHING_ROLE_ACCESS_SERVICE = 'teaching-role-access-manager';

    /**
     * @return array{actor_user_id: int|null, actor_type: string, actor_reference: string}
     */
    public function forRecording(string $action, ?User $actor): array
    {
        if ($actor !== null) {
            if (! $actor->exists || $actor->getKey() === null) {
                throw new InvalidAuditEvent('Audit actor must be a persisted user.');
            }

            return [
                'actor_user_id' => $actor->id,
                'actor_type' => self::TYPE_USER,
                'actor_reference' => $actor->public_id,
            ];
        }

        return [
            'actor_user_id' => null,
            'actor_type' => self::TYPE_SERVICE,
            'actor_reference' => $this->serviceReference($action),
        ];
    }

    public function assertValid(
        string $action,
        mixed $actorUserId,
        mixed $actorType,
        mixed $actorReference,
    ): void {
        if (! is_string($actorType) || ! is_string($actorReference)) {
            throw new InvalidAuditEvent('Audit actor type and reference are required.');
        }

        if ($actorType === self::TYPE_USER) {
            if (! is_int($actorUserId)) {
                throw new InvalidAuditEvent('USER audit attribution requires an actor user ID.');
            }

            $actor = User::query()->find($actorUserId);
            if (! $actor instanceof User || $actor->public_id !== $actorReference) {
                throw new InvalidAuditEvent('USER audit attribution must match the persisted user public ID.');
            }

            return;
        }

        if ($actorType !== self::TYPE_SERVICE || $actorUserId !== null) {
            throw new InvalidAuditEvent('Audit attribution type is invalid.');
        }

        if ($actorReference !== $this->serviceReference($action)) {
            throw new InvalidAuditEvent('SERVICE audit attribution does not match the registered action.');
        }
    }

    private function serviceReference(string $action): string
    {
        return match ($action) {
            'teaching.reset.started', 'teaching.reset.completed' => self::SYNTHETIC_RESET_SERVICE,
            'authorization.rebuild_admin.reconciled' => self::REBUILD_ADMIN_SERVICE,
            'authorization.teaching_role.activated',
            'authorization.teaching_role.revoked',
            'authorization.teaching_role.compensated' => self::TEACHING_ROLE_ACCESS_SERVICE,
            default => throw new InvalidAuditEvent('Audit event requires a persisted user actor.'),
        };
    }
}
