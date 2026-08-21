<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_responds_successfully(): void
    {
        $this->get('/up')->assertOk();
    }
}
