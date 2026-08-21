<?php

namespace App\Support\Database;

/**
 * Qualify table names for PostgreSQL when the app schema is not public.
 * PgBouncer transaction pooling can drop session SET search_path; qualified
 * names keep RBAC/domain reads reliable on hosted Supabase.
 */
final class SchemaQualifier
{
    public static function table(string $table): string
    {
        $schema = self::primarySchema();

        if ($schema === null) {
            return $table;
        }

        return $schema.'.'.$table;
    }

    public static function primarySchema(): ?string
    {
        if (config('database.default') !== 'pgsql') {
            return null;
        }

        $searchPath = (string) config('database.connections.pgsql.search_path', 'public');
        $schemas = collect(explode(',', $searchPath))
            ->map(fn (string $part): string => trim($part, " \t\n\r\0\x0B\""))
            ->filter()
            ->values();

        $primary = $schemas->first();

        if (! is_string($primary) || $primary === '' || $primary === 'public') {
            return null;
        }

        return $primary;
    }

    /**
     * @return list<string>
     */
    public static function searchPathSchemas(): array
    {
        if (config('database.default') !== 'pgsql') {
            return [];
        }

        $searchPath = (string) config('database.connections.pgsql.search_path', 'public');
        $schemas = collect(explode(',', $searchPath))
            ->map(fn (string $part): string => trim($part, " \t\n\r\0\x0B\""))
            ->filter()
            ->values();

        if ($schemas->isNotEmpty() && ! $schemas->contains('public')) {
            $schemas->push('public');
        }

        return $schemas->all();
    }
}
