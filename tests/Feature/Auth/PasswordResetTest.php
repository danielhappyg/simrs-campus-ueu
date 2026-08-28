<?php

namespace Tests\Feature\Auth;

use App\Http\Responses\IndistinguishablePasswordResetLinkResponse;
use App\Models\User;
use App\Support\Authorization\TeachingRoleAccessManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Timebox;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_public_outcome_is_identical_for_existing_unknown_and_managed_accounts(): void
    {
        Notification::fake();
        $ordinaryUser = User::factory()->create();
        $unknownEmail = 'unknown-reset@example.invalid';
        $managedEmail = (string) array_key_first(TeachingRoleAccessManager::ROSTER);

        $responses = [
            $this->resetLinkRequest($ordinaryUser->email, '198.51.100.11'),
            $this->resetLinkRequest($unknownEmail, '198.51.100.12'),
            $this->resetLinkRequest(strtoupper($managedEmail), '198.51.100.13'),
        ];

        foreach ($responses as $response) {
            $this->assertGenericResetLinkResponse($response);
        }

        $this->assertCount(1, Notification::sent($ordinaryUser, ResetPassword::class));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $ordinaryUser->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $unknownEmail]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $managedEmail]);
    }

    public function test_broker_throttled_reset_link_request_keeps_the_generic_public_outcome(): void
    {
        Notification::fake();
        config([
            'fortify.password_reset_rate_limits.email_ip_per_minute' => 100,
            'fortify.password_reset_rate_limits.ip_per_minute' => 100,
        ]);
        $user = User::factory()->create();

        $first = $this->resetLinkRequest($user->email, '198.51.100.21');
        $throttled = $this->resetLinkRequest($user->email, '198.51.100.21');

        $this->assertGenericResetLinkResponse($first);
        $this->assertGenericResetLinkResponse($throttled);
        $this->assertCount(1, Notification::sent($user, ResetPassword::class));
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_combined_email_and_ip_limit_runs_before_reset_delivery(): void
    {
        Notification::fake();
        config([
            'auth.passwords.users.throttle' => 0,
            'fortify.password_reset_rate_limits.email_ip_per_minute' => 1,
            'fortify.password_reset_rate_limits.ip_per_minute' => 100,
        ]);
        $user = User::factory()->create();

        $first = $this->resetLinkRequest($user->email, '198.51.100.31');
        $limited = $this->resetLinkRequest($user->email, '198.51.100.31');

        $this->assertGenericResetLinkResponse($first);
        $this->assertGenericResetLinkResponse($limited);
        $this->assertCount(1, Notification::sent($user, ResetPassword::class));
    }

    public function test_managed_reset_link_request_uses_the_password_broker_timebox_without_delivery(): void
    {
        Notification::fake();
        config(['auth.timebox_duration' => 345678]);
        $timebox = $this->recordingTimebox();
        $this->app->instance(Timebox::class, $timebox);
        $managedEmail = (string) array_key_first(TeachingRoleAccessManager::ROSTER);

        $response = $this->resetLinkRequest($managedEmail, '198.51.100.32');

        $this->assertGenericResetLinkResponse($response);
        $this->assertSame([345678], $timebox->durations);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $managedEmail]);
    }

    public function test_custom_limited_reset_link_request_uses_the_password_broker_timebox_without_redelivery(): void
    {
        Notification::fake();
        config([
            'auth.passwords.users.throttle' => 0,
            'auth.timebox_duration' => 456789,
            'fortify.password_reset_rate_limits.email_ip_per_minute' => 1,
            'fortify.password_reset_rate_limits.ip_per_minute' => 100,
        ]);
        $timebox = $this->recordingTimebox();
        $this->app->instance(Timebox::class, $timebox);
        $user = User::factory()->create();

        $delivered = $this->resetLinkRequest($user->email, '198.51.100.33');
        $limited = $this->resetLinkRequest($user->email, '198.51.100.33');

        $this->assertGenericResetLinkResponse($delivered);
        $this->assertGenericResetLinkResponse($limited);
        $this->assertSame([456789], $timebox->durations);
        $this->assertCount(1, Notification::sent($user, ResetPassword::class));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_wider_ip_limit_is_consumed_before_the_managed_roster_fence(): void
    {
        Notification::fake();
        config([
            'auth.passwords.users.throttle' => 0,
            'fortify.password_reset_rate_limits.email_ip_per_minute' => 100,
            'fortify.password_reset_rate_limits.ip_per_minute' => 1,
        ]);
        $user = User::factory()->create();
        $managedEmail = (string) array_key_first(TeachingRoleAccessManager::ROSTER);

        $managed = $this->resetLinkRequest($managedEmail, '198.51.100.41');
        $limited = $this->resetLinkRequest($user->email, '198.51.100.41');

        $this->assertGenericResetLinkResponse($managed);
        $this->assertGenericResetLinkResponse($limited);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $managedEmail]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    /** @return TestResponse<SymfonyResponse> */
    private function resetLinkRequest(string $email, string $ipAddress): TestResponse
    {
        return $this
            ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
            ->from(route('password.request'))
            ->post(route('password.email'), ['email' => $email]);
    }

    /** @param TestResponse<SymfonyResponse> $response */
    private function assertGenericResetLinkResponse(TestResponse $response): void
    {
        $response
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', IndistinguishablePasswordResetLinkResponse::MESSAGE)
            ->assertSessionHasNoErrors();
    }

    /** @return Timebox&object{durations: list<int>} */
    private function recordingTimebox(): Timebox
    {
        return new class extends Timebox
        {
            /** @var list<int> */
            public array $durations = [];

            public function call(callable $callback, int $microseconds): mixed
            {
                $this->durations[] = $microseconds;

                return $callback($this);
            }
        };
    }
}
