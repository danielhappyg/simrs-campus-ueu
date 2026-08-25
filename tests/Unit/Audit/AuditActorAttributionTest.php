<?php

namespace Tests\Unit\Audit;

use App\Models\User;
use App\Support\Audit\AuditActorAttribution;
use App\Support\Audit\InvalidAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditActorAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_derives_user_attribution_from_a_persisted_user(): void
    {
        $actor = User::factory()->create();

        $this->assertSame([
            'actor_user_id' => $actor->id,
            'actor_type' => AuditActorAttribution::TYPE_USER,
            'actor_reference' => $actor->public_id,
        ], $this->attribution()->forRecording('patient.register', $actor));
    }

    public function test_it_derives_only_registered_service_attribution(): void
    {
        $this->assertSame([
            'actor_user_id' => null,
            'actor_type' => AuditActorAttribution::TYPE_SERVICE,
            'actor_reference' => AuditActorAttribution::SYNTHETIC_RESET_SERVICE,
        ], $this->attribution()->forRecording('teaching.reset.started', null));

        $this->assertSame([
            'actor_user_id' => null,
            'actor_type' => AuditActorAttribution::TYPE_SERVICE,
            'actor_reference' => AuditActorAttribution::REBUILD_ADMIN_SERVICE,
        ], $this->attribution()->forRecording('authorization.rebuild_admin.reconciled', null));
    }

    public function test_it_rejects_an_unregistered_service_action(): void
    {
        $this->expectException(InvalidAuditEvent::class);

        $this->attribution()->forRecording('patient.register', null);
    }

    public function test_it_rejects_a_user_reference_that_does_not_match_the_persisted_user(): void
    {
        $actor = User::factory()->create();

        $this->expectException(InvalidAuditEvent::class);

        $this->attribution()->assertValid(
            action: 'patient.register',
            actorUserId: $actor->id,
            actorType: AuditActorAttribution::TYPE_USER,
            actorReference: '01J00000000000000000000000',
        );
    }

    public function test_it_rejects_a_service_reference_registered_to_another_action(): void
    {
        $this->expectException(InvalidAuditEvent::class);

        $this->attribution()->assertValid(
            action: 'teaching.reset.started',
            actorUserId: null,
            actorType: AuditActorAttribution::TYPE_SERVICE,
            actorReference: AuditActorAttribution::REBUILD_ADMIN_SERVICE,
        );
    }

    private function attribution(): AuditActorAttribution
    {
        return $this->app->make(AuditActorAttribution::class);
    }
}
