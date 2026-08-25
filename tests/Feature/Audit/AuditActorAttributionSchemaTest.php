<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Support\Database\SchemaQualifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AuditActorAttributionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_nullable_actor_attribution_columns_and_composite_index(): void
    {
        $this->assertTrue(Schema::hasColumns('audit_events', ['actor_type', 'actor_reference']));
        $this->assertTrue(
            Schema::hasIndex('audit_events', ['actor_type', 'actor_reference']),
        );
    }

    public function test_referenced_user_deletion_is_rejected_while_status_disable_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->insertAuditEvent($user);

        $user->forceFill(['status' => 'DISABLED'])->save();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'DISABLED']);
        $this->assertDatabaseHas('audit_events', ['actor_user_id' => $user->id]);

        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_unreferenced_user_deletion_is_allowed(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->delete());
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_populated_down_refuses_without_weakening_the_foreign_key(): void
    {
        $user = User::factory()->create();
        $this->insertAuditEvent($user);

        try {
            $this->runMigration('down');
            $this->fail('Rollback must refuse to remove populated attribution evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ordinary audit evidence exists', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumns('audit_events', ['actor_type', 'actor_reference']));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_events', ['actor_user_id' => $user->id]);

        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_empty_down_restores_set_null_and_supports_reapplying_the_expansion(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL DDL auto-commits; the destructive cycle requires an isolated backend database.');
        }

        $this->runMigration('down');

        $this->assertFalse(Schema::hasColumn('audit_events', 'actor_type'));
        $this->assertFalse(Schema::hasColumn('audit_events', 'actor_reference'));

        $legacyUser = User::factory()->create();
        $this->insertAuditEvent($legacyUser);
        $legacyUser->delete();
        $this->assertDatabaseHas('audit_events', ['actor_user_id' => null]);

        $this->runMigration('up');

        $this->assertTrue(Schema::hasColumns('audit_events', ['actor_type', 'actor_reference']));
        $this->assertTrue(
            Schema::hasIndex('audit_events', ['actor_type', 'actor_reference']),
        );
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => null,
            'actor_type' => null,
            'actor_reference' => null,
        ]);

        $attributedUser = User::factory()->create();
        $this->insertAuditEvent($attributedUser);
        $this->assertDatabaseHas('users', ['id' => $attributedUser->id]);

        $this->expectException(QueryException::class);
        $attributedUser->delete();
    }

    public function test_postgres_public_configuration_still_targets_the_private_laravel_schema(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL private-schema contract only.');
        }

        config(['database.connections.pgsql.search_path' => 'public']);
        DB::statement('SET LOCAL search_path TO public');

        $this->runMigration('down');
        $this->assertFalse(Schema::hasColumn('laravel.audit_events', 'actor_type'));
        $this->runMigration('up');

        $this->assertTrue(Schema::hasColumns('laravel.audit_events', ['actor_type', 'actor_reference']));
        $publicAuditTables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', 'audit_events')
            ->count();
        $this->assertSame(0, $publicAuditTables);
    }

    private function insertAuditEvent(User $actor): void
    {
        $attributes = [
            'id' => (string) Str::ulid(),
            'recorded_at' => now(),
            'actor_user_id' => $actor->id,
            'action' => 'authorization.denied',
            'resource_type' => 'http_route',
            'resource_id' => 'audit.actor-attribution-schema',
            'resource_version' => null,
            'outcome' => 'DENIED',
            'reason' => 'authorization_check_failed',
            'request_correlation_id' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'metadata' => json_encode(['http_method' => 'GET', 'http_status' => 403], JSON_THROW_ON_ERROR),
        ];

        if (Schema::hasColumns('audit_events', ['actor_type', 'actor_reference'])) {
            $attributes['actor_type'] = 'USER';
            $attributes['actor_reference'] = $actor->public_id;
        }

        DB::table(SchemaQualifier::table('audit_events'))->insert($attributes);
    }

    private function runMigration(string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Unsupported test migration direction.');
        }

        $migration = require database_path('migrations/2026_08_25_000300_expand_audit_actor_attribution.php');
        $migration->{$direction}();
    }
}
