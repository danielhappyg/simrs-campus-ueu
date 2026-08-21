<?php

namespace App\Support\Models;

use App\Support\Database\SchemaQualifier;

/**
 * Prefer schema-qualified table names on pooled Postgres (search_path can drift).
 */
trait UsesSchemaQualifiedTable
{
    public function getTable(): string
    {
        $base = parent::getTable();

        if (str_contains($base, '.')) {
            return $base;
        }

        return SchemaQualifier::table($base);
    }
}
