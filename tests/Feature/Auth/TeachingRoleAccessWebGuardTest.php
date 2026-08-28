<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use App\Support\Authentication\PasskeyRouteKey;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class TeachingRoleAccessWebGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ROSTER_EMAIL = 'registrar.demo@example.invalid';

    private const LEASE_PASSWORD = 'UEU-Teaching-Role-2026-Access';

    private const ENVIRONMENT = 'test-simulation';

    private const RELEASE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'simulation.teaching_role_access_commitment_key' => 'test-only-teaching-role-commitment-key-2026',
            'simulation.teaching_role_access_environment' => self::ENVIRONMENT,
            'simulation.teaching_role_access_release_sha' => self::RELEASE_SHA,
            'simulation.teaching_role_access_deployment_url' => 'test-deployment.vercel.app',
            'simulation.teaching_role_access_canonical_host' => 'localhost',
        ]);
    }

    public function test_valid_roster_password_login_stamps_lease_session_and_forces_remember_off(): void
    {
        [$user, $lease] = $this->activeRosterLease();
        $guard = app(TeachingRoleAccessLeaseGuard::class);
        $fresh = $user->fresh();

        $this->assertTrue(Hash::check(self::LEASE_PASSWORD, $user->password));
        $this->assertSame('TEACHING_ACTIVE', $fresh->status);
        $this->assertFalse($fresh->is_system_administrator);
        $this->assertSame(['registrar'], $fresh->roleSlugs());
        $this->assertSame($lease->public_id, $fresh->teaching_access_lease_public_id);
        $this->assertSame($lease->expires_at_epoch, $fresh->teaching_access_expires_at_epoch);
        $this->assertGreaterThan($guard->databaseEpoch(), $lease->expires_at_epoch);
        $this->assertTrue($guard->allows($user));
        $this->assertTrue($guard->allowsPassword($user, self::LEASE_PASSWORD));

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::LEASE_PASSWORD,
            'remember' => true,
        ]);

        $response
            ->assertRedirect(route('home', absolute: false))
            ->assertSessionHas(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY, 7)
            ->assertSessionHas(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY, $lease->public_id)
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_expired_roster_lease_cannot_password_login(): void
    {
        [$user] = $this->activeRosterLease(
            expiresAtEpoch: app(TeachingRoleAccessLeaseGuard::class)->databaseEpoch() - 60,
        );

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::LEASE_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_exact_roster_email_requires_its_immutable_marker_for_password_login(): void
    {
        [$user] = $this->activeRosterLease();

        if (DB::connection()->getDriverName() === 'pgsql') {
            try {
                DB::transaction(function () use ($user): void {
                    $user->forceFill(['teaching_access_roster_key' => null])->save();
                });
                $this->fail('PostgreSQL must reject mutation of a bound teaching-roster marker.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Teaching roster identity is immutable', $exception->getMessage());
            }

            $this->assertSame('registrar', $user->fresh()->teaching_access_roster_key);

            return;
        }

        foreach ([null, 'invalid-marker'] as $marker) {
            $user->forceFill([
                'status' => $marker === null ? 'TEACHING_ACTIVE' : 'ACTIVE',
                'teaching_access_roster_key' => $marker,
            ])->save();

            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => self::LEASE_PASSWORD,
            ])->assertSessionHasErrors('email');

            $this->assertGuest();
        }
    }

    public function test_deployment_canonical_and_request_host_drift_block_roster_login(): void
    {
        [$user] = $this->activeRosterLease();

        foreach ([
            ['simulation.teaching_role_access_deployment_url', 'wrong-deployment.vercel.app', 'localhost'],
            ['simulation.teaching_role_access_canonical_host', 'wrong-canonical.example.invalid', 'localhost'],
            [null, null, 'wrong-request-host.example.invalid'],
        ] as [$key, $value, $host]) {
            if (is_string($key)) {
                config([$key => $value]);
            }

            $loginUrl = $key === null ? 'http://'.$host.'/login' : route('login.store');
            $this->withServerVariables(['HTTP_HOST' => $host])
                ->post($loginUrl, [
                    'email' => $user->email,
                    'password' => self::LEASE_PASSWORD,
                ])
                ->assertSessionHasErrors('email');
            $this->assertGuest();

            config([
                'simulation.teaching_role_access_deployment_url' => 'test-deployment.vercel.app',
                'simulation.teaching_role_access_canonical_host' => 'localhost',
            ]);
        }
    }

    public function test_roster_session_epoch_mismatch_is_logged_out_and_invalidated(): void
    {
        [$user, $lease] = $this->activeRosterLease();

        $response = $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 6,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
            ])
            ->get(route('home'));

        $response
            ->assertForbidden()
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY)
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);
        $this->assertGuest();
    }

    public function test_matching_roster_lease_session_can_reach_the_application(): void
    {
        [$user, $lease] = $this->activeRosterLease();

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
            ])
            ->get(route('home'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_roster_session_is_invalidated_after_password_state_drift(): void
    {
        [$user, $lease] = $this->activeRosterLease();
        $user->forceFill(['password' => 'externally-rotated-password-state'])->save();

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
            ])
            ->get(route('home'))
            ->assertForbidden()
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY)
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);

        $this->assertGuest();
    }

    public function test_roster_session_is_revalidated_after_the_controller_response(): void
    {
        [$user, $lease] = $this->activeRosterLease();
        Route::middleware('web')->get('/_test/teaching-role-late-revoke', function (Request $request) {
            $current = $request->user();
            if ($current instanceof User) {
                $current->forceFill([
                    'status' => 'DISABLED',
                    'teaching_access_epoch' => (int) $current->teaching_access_epoch + 1,
                    'teaching_access_lease_public_id' => null,
                    'teaching_access_expires_at_epoch' => null,
                ])->save();
            }

            return response('must-not-complete');
        });

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
            ])
            ->get('/_test/teaching-role-late-revoke')
            ->assertForbidden()
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY)
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);

        $this->assertGuest();
    }

    public function test_stale_mutating_roster_request_rolls_back_its_domain_delta_before_returning_forbidden(): void
    {
        [$user, $lease] = $this->activeRosterLease();
        $role = Role::query()->where('slug', 'registrar')->sole();
        $originalDescription = $role->description;
        Route::middleware(['web', 'auth', 'active.account'])
            ->post('/_test/teaching-role-fenced-mutation', function () use ($lease) {
                Role::query()->where('slug', 'registrar')->update([
                    'description' => 'must be rolled back by the teaching-role mutation fence',
                ]);
                TeachingRoleAccessLease::query()->whereKey($lease->id)->update([
                    'status' => 'REVOKED',
                    'active_slot' => null,
                    'ended_at_epoch' => app(TeachingRoleAccessLeaseGuard::class)->databaseWallClockEpoch(),
                ]);

                return response('must-not-commit');
            });

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
            ])
            ->post('/_test/teaching-role-fenced-mutation')
            ->assertForbidden()
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY)
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);

        $this->assertSame($originalDescription, $role->fresh()->description);
        $this->assertSame('ACTIVE', $lease->fresh()->status);
        $this->assertSame(1, $lease->fresh()->active_slot);
        $this->assertGuest();
    }

    public function test_postgres_committed_revocation_fences_and_rolls_back_an_inflight_domain_write(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL two-connection mutation fencing proof.');
        }

        $connectionName = 'teaching_fence_b';
        config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);
        DB::purge($connectionName);
        $revoker = DB::connection($connectionName);
        $this->assertNotSame(
            (int) DB::scalar('SELECT pg_backend_pid()'),
            (int) $revoker->scalar('SELECT pg_backend_pid()'),
        );

        $guard = app(TeachingRoleAccessLeaseGuard::class);
        $nowEpoch = $guard->databaseWallClockEpoch();
        $expiresAtEpoch = $nowEpoch + 900;
        $leasePublicId = (string) Str::ulid();
        $roleId = (int) $revoker->table('roles')->insertGetId([
            'slug' => 'registrar',
            'name' => 'Registrar',
            'description' => 'PostgreSQL committed revocation fence fixture.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $passwordHash = Hash::make(self::LEASE_PASSWORD);
        $userId = (int) $revoker->table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'name' => 'PostgreSQL Fence Registrar',
            'email' => self::ROSTER_EMAIL,
            'email_verified_at' => now(),
            'password' => $passwordHash,
            'status' => 'TEACHING_ACTIVE',
            'is_system_administrator' => false,
            'remember_token' => null,
            'teaching_access_epoch' => 7,
            'teaching_access_mutex' => 7,
            'teaching_access_roster_key' => 'registrar',
            'teaching_access_lease_public_id' => $leasePublicId,
            'teaching_access_expires_at_epoch' => $expiresAtEpoch,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revoker->table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
        $leaseId = (int) $revoker->table('teaching_role_access_leases')->insertGetId([
            'public_id' => $leasePublicId,
            'user_id' => $userId,
            'expected_role' => 'registrar',
            'credential_commitment' => $guard->credentialCommitment(self::LEASE_PASSWORD),
            'password_state_commitment' => $guard->passwordStateCommitment($passwordHash),
            'environment' => self::ENVIRONMENT,
            'release_sha' => self::RELEASE_SHA,
            'deployment_url' => 'test-deployment.vercel.app',
            'canonical_host' => 'localhost',
            'status' => 'ACTIVE',
            'active_slot' => 1,
            'activated_at_epoch' => $nowEpoch,
            'expires_at_epoch' => $expiresAtEpoch,
            'operator' => 'postgres-fence-test',
            'reason' => 'Synthetic two-connection committed revocation proof.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Route::middleware(['web', 'auth', 'active.account'])
                ->post('/_test/postgres-teaching-role-committed-revoke', function () use (
                    $revoker,
                    $userId,
                    $leaseId,
                    $roleId,
                ) {
                    Role::query()->whereKey($roleId)->update([
                        'description' => 'must roll back after committed revocation',
                    ]);
                    $revoker->transaction(function () use ($revoker, $userId, $leaseId): void {
                        $revoker->table('users')->where('id', $userId)->update([
                            'status' => 'DISABLED',
                            'teaching_access_epoch' => 8,
                            'teaching_access_mutex' => 8,
                            'teaching_access_lease_public_id' => null,
                            'teaching_access_expires_at_epoch' => null,
                        ]);
                        $revoker->table('teaching_role_access_leases')->where('id', $leaseId)->update([
                            'status' => 'REVOKED',
                            'active_slot' => null,
                            'ended_at_epoch' => app(TeachingRoleAccessLeaseGuard::class)->databaseWallClockEpoch(),
                        ]);
                    });

                    return response('must-not-commit');
                });

            $user = User::query()->findOrFail($userId);
            $this->actingAs($user)
                ->withSession([
                    TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                    TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $leasePublicId,
                ])
                ->post('/_test/postgres-teaching-role-committed-revoke')
                ->assertForbidden();

            $this->assertSame(
                'PostgreSQL committed revocation fence fixture.',
                $revoker->table('roles')->where('id', $roleId)->value('description'),
            );
            $this->assertSame('DISABLED', $revoker->table('users')->where('id', $userId)->value('status'));
            $this->assertSame(8, (int) $revoker->table('users')->where('id', $userId)->value('teaching_access_epoch'));
            $this->assertSame('REVOKED', $revoker->table('teaching_role_access_leases')->where('id', $leaseId)->value('status'));
        } finally {
            $revoker->table('teaching_role_access_leases')->where('id', $leaseId)->delete();
            $revoker->table('role_user')->where('user_id', $userId)->delete();
            $revoker->table('users')->where('id', $userId)->delete();
            $revoker->table('roles')->where('id', $roleId)->delete();
            DB::disconnect($connectionName);
        }
    }

    public function test_roster_password_reset_request_is_non_enumerating_and_issues_no_token(): void
    {
        Notification::fake();
        [$user] = $this->activeRosterLease();

        $response = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => strtoupper($user->email)]);

        $response
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_roster_password_reset_token_cannot_be_consumed(): void
    {
        [$user] = $this->activeRosterLease();
        $originalHash = $user->password;
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'replacement-password-2026',
            'password_confirmation' => 'replacement-password-2026',
        ])->assertForbidden();

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_roster_two_factor_challenge_completion_is_blocked_before_login(): void
    {
        [$user] = $this->activeRosterLease();

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.remember' => true,
        ])->post(route('two-factor.login.store'), ['code' => '000000']);

        $response
            ->assertForbidden()
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('login.remember');
        $this->assertGuest();
    }

    public function test_roster_passkey_login_and_management_are_blocked_while_non_roster_callback_is_unchanged(): void
    {
        [$user, $lease] = $this->activeRosterLease();
        $passkey = (new Passkey)->setRelation('user', $user);

        $this->assertFalse(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey));

        $ordinaryUser = User::factory()->create();
        $ordinaryPasskey = (new Passkey)->setRelation('user', $ordinaryUser);
        $this->assertTrue(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $ordinaryPasskey));

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
                'auth.password_confirmed_at' => time(),
            ])
            ->get(route('passkey.registration-options'))
            ->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_non_roster_passkey_login_and_management_are_blocked(): void
    {
        $user = User::factory()->create(['status' => 'SUSPENDED']);
        /** @var Passkey $passkey */
        $passkey = $user->passkeys()->create([
            'name' => 'Suspended account passkey',
            'credential_id' => 'suspended-account-credential',
            'credential' => [],
        ]);

        $this->assertFalse(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('passkey.registration-options'))
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('passkey.destroy', [
                'passkey' => app(PasskeyRouteKey::class)->for($passkey),
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->getKey()]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_roster_session_is_invalidated_on_a_package_managed_auth_route(): void
    {
        [$user, $lease] = $this->activeRosterLease();
        $expiredAtEpoch = app(TeachingRoleAccessLeaseGuard::class)->databaseEpoch() - 60;
        $user->forceFill(['teaching_access_expires_at_epoch' => $expiredAtEpoch])->save();
        $lease->forceFill(['expires_at_epoch' => $expiredAtEpoch])->save();

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
                'auth.password_confirmed_at' => time(),
            ])
            ->get(route('passkey.registration-options'))
            ->assertForbidden()
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY)
            ->assertSessionMissing(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);

        $this->assertGuest();
    }

    public function test_roster_email_verification_and_credential_settings_mutations_are_blocked(): void
    {
        [$user, $lease] = $this->activeRosterLease(verified: false);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(10),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
            ])
            ->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)
            ->withSession([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => 7,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => $lease->public_id,
                'auth.password_confirmed_at' => time(),
            ])
            ->put(route('user-password.update'), [
                'current_password' => self::LEASE_PASSWORD,
                'password' => 'replacement-password-2026',
                'password_confirmation' => 'replacement-password-2026',
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::LEASE_PASSWORD, $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_non_roster_login_and_password_reset_behavior_remain_available(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home', absolute: false));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /** @return array{User, TeachingRoleAccessLease} */
    private function activeRosterLease(
        ?int $expiresAtEpoch = null,
        bool $verified = true,
    ): array {
        $guard = app(TeachingRoleAccessLeaseGuard::class);
        $nowEpoch = $guard->databaseEpoch();
        $expiresAtEpoch ??= $nowEpoch + (15 * 60);
        $leasePublicId = (string) Str::ulid();

        $user = User::factory()->create([
            'email' => self::ROSTER_EMAIL,
            'email_verified_at' => $verified ? now() : null,
            'password' => self::LEASE_PASSWORD,
            'remember_token' => null,
        ]);
        $user->forceFill([
            'status' => 'TEACHING_ACTIVE',
            'teaching_access_epoch' => 7,
            'teaching_access_mutex' => 7,
            'teaching_access_roster_key' => 'registrar',
            'teaching_access_lease_public_id' => $leasePublicId,
            'teaching_access_expires_at_epoch' => $expiresAtEpoch,
        ])->save();
        $role = Role::query()->create([
            'slug' => 'registrar',
            'name' => 'Registrar',
            'description' => 'Focused teaching-role web guard test role.',
        ]);
        $user->roles()->sync([$role->id]);

        $lease = TeachingRoleAccessLease::query()->create([
            'public_id' => $leasePublicId,
            'user_id' => $user->id,
            'expected_role' => 'registrar',
            'credential_commitment' => $guard->credentialCommitment(self::LEASE_PASSWORD),
            'password_state_commitment' => $guard->passwordStateCommitment($user->password),
            'environment' => self::ENVIRONMENT,
            'release_sha' => self::RELEASE_SHA,
            'deployment_url' => 'test-deployment.vercel.app',
            'canonical_host' => 'localhost',
            'status' => 'ACTIVE',
            'active_slot' => 1,
            'activated_at_epoch' => $nowEpoch,
            'expires_at_epoch' => $expiresAtEpoch,
            'operator' => 'automated-test',
            'reason' => 'Focused web authentication lease guard verification.',
        ]);

        return [$user->fresh(), $lease];
    }
}
