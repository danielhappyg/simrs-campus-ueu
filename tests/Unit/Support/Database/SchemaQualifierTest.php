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
}
