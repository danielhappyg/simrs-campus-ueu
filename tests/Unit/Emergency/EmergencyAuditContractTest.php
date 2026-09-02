<?php

namespace Tests\Unit\Emergency;

use App\Support\Audit\AuditEventSchemaRegistry;
use App\Support\Audit\InvalidAuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmergencyAuditContractTest extends TestCase
{
    private const ULID = '01J00000000000000000000000';

    public function test_it_accepts_registered_success_and_denial_events(): void
    {
        $registry = new AuditEventSchemaRegistry;

        $registry->assertAllows(
            'emergency.workflow.mutate',
            'emergency_record',
            null,
            true,
            'SUCCESS',
            null,
            ['operation' => 'EMERGENCY_TRIAGE_VOCABULARY_CREATE'],
        );
        $registry->assertAllows(
            'emergency.workflow.mutate',
            'emergency_record',
            self::ULID,
            true,
            'DENIED',
            'stale_version',
            ['operation' => 'EMERGENCY_TRIAGE_REASSESS'],
        );

        $this->addToAssertionCount(2);
    }

    /** @param array<string, mixed> $event */
    #[DataProvider('invalidEmergencyEvents')]
    public function test_it_rejects_unregistered_or_malformed_emergency_events(array $event): void
    {
        $this->expectException(InvalidAuditEvent::class);

        (new AuditEventSchemaRegistry)->assertAllows(...$event);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEmergencyEvents(): iterable
    {
        $base = [
            'action' => 'emergency.workflow.mutate',
            'resourceType' => 'emergency_record',
            'resourceId' => self::ULID,
            'actorPresent' => true,
            'outcome' => 'DENIED',
            'reason' => 'stale_version',
            'metadata' => ['operation' => 'EMERGENCY_TRIAGE_REASSESS'],
        ];

        yield 'unknown operation' => [[...$base, 'metadata' => ['operation' => 'EMERGENCY_UNKNOWN']]];
        yield 'unknown denial reason' => [[...$base, 'reason' => 'unreviewed_reason']];
        yield 'unexpected metadata' => [[...$base, 'metadata' => ['operation' => 'EMERGENCY_TRIAGE_REASSESS', 'patient_name' => 'forbidden']]];
        yield 'missing actor' => [[...$base, 'actorPresent' => false]];
    }
}
