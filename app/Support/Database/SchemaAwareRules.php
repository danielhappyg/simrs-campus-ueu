<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation presence rules that honor schema-qualified Eloquent tables.
 *
 * Do NOT pass "laravel.clinics" strings into Rule::exists()/unique() — Laravel
 * parseTable() treats the first dotted segment as a DB connection name, which
 * yields "Database connection [laravel] not configured" (HTTP 500). Pass the
 * model class so ValidatesAttributes resolves getTable() safely.
 */
final class SchemaAwareRules
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function exists(string $modelClass, string $column): Exists
    {
        return Rule::exists($modelClass, $column);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function unique(string $modelClass, string $column): Unique
    {
        return Rule::unique($modelClass, $column);
    }
}
