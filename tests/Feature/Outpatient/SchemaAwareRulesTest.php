<?php

namespace Tests\Feature\Outpatient;

use App\Models\Clinic;
use App\Models\Patient;
use App\Support\Database\SchemaAwareRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;

class SchemaAwareRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_exists_and_unique_rules_do_not_emit_schema_connection_strings(): void
    {
        $exists = SchemaAwareRules::exists(Clinic::class, 'public_id');
        $unique = SchemaAwareRules::unique(Patient::class, 'medical_record_number');

        $this->assertStringContainsString('public_id', (string) $exists);
        $this->assertStringContainsString('medical_record_number', (string) $unique);
        $this->assertStringNotContainsString('laravel.', (string) $exists);
        $this->assertStringNotContainsString('laravel.', (string) $unique);
    }

    public function test_laravel_parse_table_treats_dotted_name_as_connection(): void
    {
        $validator = Validator::make([], []);
        $method = new ReflectionMethod($validator, 'parseTable');

        /** @var array{0: ?string, 1: string, 2: ?string} $parsed */
        $parsed = $method->invoke($validator, 'laravel.clinics');

        $this->assertSame('laravel', $parsed[0]);
        $this->assertSame('clinics', $parsed[1]);
    }

    public function test_model_class_exists_rule_keeps_schema_qualified_table_without_fake_connection(): void
    {
        $validator = Validator::make([], []);
        $method = new ReflectionMethod($validator, 'parseTable');

        /** @var array{0: ?string, 1: string, 2: ?string} $parsed */
        $parsed = $method->invoke($validator, Clinic::class);

        $this->assertNotSame('laravel', $parsed[0]);
        $this->assertSame((new Clinic)->getTable(), $parsed[1]);
    }
}
