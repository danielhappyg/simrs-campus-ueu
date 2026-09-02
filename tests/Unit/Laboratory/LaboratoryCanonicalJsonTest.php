<?php

namespace Tests\Unit\Laboratory;

use App\Support\Laboratory\LaboratoryCanonicalJson;
use Tests\TestCase;

final class LaboratoryCanonicalJsonTest extends TestCase
{
    public function test_associative_key_order_is_portable_but_list_order_remains_semantic(): void
    {
        $applicationOrder = [[
            'code' => 'HGB',
            'display_name' => 'Hemoglobin',
            'value_kind' => 'NUMERIC',
            'unit_text' => 'g/dL',
            'reference_text' => '12-16',
            'critical_allowed' => true,
        ]];
        $mysqlJsonOrder = [[
            'code' => 'HGB',
            'critical_allowed' => true,
            'display_name' => 'Hemoglobin',
            'reference_text' => '12-16',
            'unit_text' => 'g/dL',
            'value_kind' => 'NUMERIC',
        ]];

        $this->assertSame(
            LaboratoryCanonicalJson::digest($applicationOrder),
            LaboratoryCanonicalJson::digest($mysqlJsonOrder),
        );
        $this->assertNotSame(
            LaboratoryCanonicalJson::digest([['code' => 'A'], ['code' => 'B']]),
            LaboratoryCanonicalJson::digest([['code' => 'B'], ['code' => 'A']]),
        );
    }
}
