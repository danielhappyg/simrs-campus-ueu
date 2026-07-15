<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_receives_ulid_and_uses_it_for_route_binding(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $user->public_id);
        $this->assertSame('public_id', $user->getRouteKeyName());
        $this->assertSame($user->public_id, $user->getRouteKey());
    }
}
