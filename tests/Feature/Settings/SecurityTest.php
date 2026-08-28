<?php

namespace Tests\Feature\Settings;

use App\Http\Responses\OpaquePasskeyRegistrationResponse;
use App\Models\User;
use App\Support\Authentication\PasskeyRouteKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_is_displayed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        Features::passkeys([
            'confirmPassword' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('canManagePasskeys', true)
                ->where('passkeys', [])
                ->where('canManageTwoFactor', true)
                ->where('twoFactorEnabled', false),
            );
    }

    public function test_security_page_requires_password_confirmation_when_enabled(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('canManagePasskeys', false)
                ->where('passkeys', [])
                ->where('canManageTwoFactor', false)
                ->missing('twoFactorEnabled')
                ->missing('requiresConfirmation'),
            );
    }

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('security.edit'));
    }

    public function test_security_page_exposes_only_an_opaque_passkey_identifier(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());

        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, 'security-page-credential');
        $routeKeys = app(PasskeyRouteKey::class);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('passkeys.0.id', fn ($value) => is_string($value)
                    && ! ctype_digit($value)
                    && $routeKeys->resolveFor($user, $value)?->is($passkey) === true)
                ->where('passkeys.0.name', 'Test passkey'));
    }

    public function test_passkey_delete_route_accepts_only_the_owners_opaque_identifier(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $passkey = $this->createPasskey($user, 'owner-credential');
        $otherPasskey = $this->createPasskey($otherUser, 'other-owner-credential');
        $routeKeys = app(PasskeyRouteKey::class);
        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)
            ->withSession($session)
            ->delete(route('passkey.destroy', ['passkey' => $passkey->getKey()]))
            ->assertNotFound();
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->getKey()]);

        $this->actingAs($user)
            ->withSession($session)
            ->delete(route('passkey.destroy', ['passkey' => $routeKeys->for($otherPasskey)]))
            ->assertNotFound();
        $this->assertDatabaseHas('passkeys', ['id' => $otherPasskey->getKey()]);

        $this->actingAs($user)
            ->withSession($session)
            ->from(route('security.edit'))
            ->delete(route('passkey.destroy', ['passkey' => $routeKeys->for($passkey)]))
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->getKey()]);
    }

    public function test_passkey_registration_response_uses_the_same_opaque_identifier(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, 'registration-response-credential');
        $request = Request::create('/user/passkeys', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(PasskeyRegistrationResponse::class)
            ->withPasskey($passkey)
            ->toResponse($request);

        $this->assertInstanceOf(OpaquePasskeyRegistrationResponse::class, app(PasskeyRegistrationResponse::class));
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('passkey-registered', $payload['status']);
        $this->assertSame('Test passkey', $payload['name']);
        $this->assertIsString($payload['id']);
        $this->assertFalse(ctype_digit($payload['id']));
        $this->assertTrue(app(PasskeyRouteKey::class)->resolveFor($user, $payload['id'])?->is($passkey));
    }

    private function createPasskey(User $user, string $credentialId): Passkey
    {
        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->create([
            'name' => 'Test passkey',
            'credential_id' => $credentialId,
            'credential' => [],
        ]);

        return $passkey;
    }
}
