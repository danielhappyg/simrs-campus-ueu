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
        $schemas = self::configuredSchemas();

        if ($schemas === []) {
            return null;
        }

        $primary = $schemas[0];

        if ($primary === 'public') {
            // Hosted synthetic demo keeps app tables in private `laravel`.
            // Prefer that schema over unqualified public lookups when env drifts.
            return self::shouldPreferLaravelSchema() ? 'laravel' : null;
        }

        return $primary;
    }

    /**
     * @return list<string>
     */
    public static function searchPathSchemas(): array
    {
        $schemas = collect(self::configuredSchemas());

        if ($schemas->isEmpty()) {
            return [];
        }

        if ($schemas->first() === 'public' && self::shouldPreferLaravelSchema()) {
            $schemas = collect(['laravel', 'public']);
        } elseif (! $schemas->contains('public')) {
            $schemas->push('public');
        }

        return $schemas->values()->all();
    }

    /**
     * @return list<string>
     */
    private static function configuredSchemas(): array
    {
        if (config('database.default') !== 'pgsql') {
            return [];
        }

        $searchPath = (string) config('database.connections.pgsql.search_path', '');

        if ($searchPath === '') {
            $searchPath = (string) env('DB_SCHEMA', 'public');
        }

        return collect(explode(',', $searchPath))
            ->map(fn (string $part): string => trim($part, " \t\n\r\0\x0B\""))
            ->filter()
            ->values()
            ->all();
    }

    private static function shouldPreferLaravelSchema(): bool
    {
        return filter_var(env('APP_SYNTHETIC_ONLY', false), FILTER_VALIDATE_BOOLEAN)
            || strtoupper((string) env('APP_MODE', '')) === 'SIMULATION';
    }
}
