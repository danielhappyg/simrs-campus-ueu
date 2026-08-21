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
        ]);

        putenv('APP_SYNTHETIC_ONLY=true');
        $_ENV['APP_SYNTHETIC_ONLY'] = 'true';
        $_SERVER['APP_SYNTHETIC_ONLY'] = 'true';

        try {
            $this->assertSame('laravel', SchemaQualifier::primarySchema());
            $this->assertSame('laravel.role_user', SchemaQualifier::table('role_user'));
            $this->assertSame(['laravel', 'public'], SchemaQualifier::searchPathSchemas());
        } finally {
            putenv('APP_SYNTHETIC_ONLY');
            unset($_ENV['APP_SYNTHETIC_ONLY'], $_SERVER['APP_SYNTHETIC_ONLY']);
        }
    }

    public function test_public_search_path_without_synthetic_flag_does_not_qualify(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.search_path' => 'public',
        ]);

        putenv('APP_SYNTHETIC_ONLY=false');
        $_ENV['APP_SYNTHETIC_ONLY'] = 'false';
        $_SERVER['APP_SYNTHETIC_ONLY'] = 'false';
        putenv('APP_MODE=LOCAL');
        $_ENV['APP_MODE'] = 'LOCAL';
        $_SERVER['APP_MODE'] = 'LOCAL';

        try {
            $this->assertNull(SchemaQualifier::primarySchema());
            $this->assertSame('role_user', SchemaQualifier::table('role_user'));
        } finally {
            putenv('APP_SYNTHETIC_ONLY');
            putenv('APP_MODE');
            unset(
                $_ENV['APP_SYNTHETIC_ONLY'],
                $_SERVER['APP_SYNTHETIC_ONLY'],
                $_ENV['APP_MODE'],
                $_SERVER['APP_MODE'],
            );
        }
    }
}
