<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    public function test_web_response_contains_a_ulid_request_identifier(): void
    {
        $response = $this->get(route('login'));
        $requestId = $response->headers->get('X-Request-Id');

        $response->assertOk();
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestId);
    }
}
