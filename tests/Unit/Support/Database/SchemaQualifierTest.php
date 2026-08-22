<?php

namespace Tests\Unit\Support\Database;

use App\Support\Database\SchemaQualifier;
use Tests\TestCase;

class SchemaQualifierTest extends TestCase
{
    public function test_sqlite_default_does_not_qualify_tables(): void
    {
        $this->assertNull(SchemaQualifier::primarySchema());
        $this->assertSame('role_user', SchemaQualifier::table('role_user'));
    }

    public function test_pgsql_laravel_schema_qualifies_tables(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.search_path' => 'laravel',
        ]);

        $this->assertSame('laravel', SchemaQualifier::primarySchema());
        $this->assertSame('laravel.role_user', SchemaQualifier::table('role_user'));
        $this->assertSame(['laravel', 'public'], SchemaQualifier::searchPathSchemas());
    }

    public function test_synthetic_demo_prefers_laravel_when_search_path_is_public(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.search_path' => 'public',
            'simulation.synthetic_only' => true,
            'simulation.mode' => 'SIMULATION',
        ]);

        $this->assertSame('laravel', SchemaQualifier::primarySchema());
        $this->assertSame('laravel.role_user', SchemaQualifier::table('role_user'));
        $this->assertSame(['laravel', 'public'], SchemaQualifier::searchPathSchemas());
    }

    public function test_public_search_path_without_synthetic_flag_does_not_qualify(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.search_path' => 'public',
            'simulation.synthetic_only' => false,
            'simulation.mode' => 'LOCAL',
        ]);

        $this->assertNull(SchemaQualifier::primarySchema());
        $this->assertSame('role_user', SchemaQualifier::table('role_user'));
    }
}
