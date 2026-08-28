<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\IndistinguishablePasswordResetLinkResponse;
use App\Http\Responses\OpaquePasskeyRegistrationResponse;
use App\Models\User;
use App\Support\Authentication\PasskeyRouteKey;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            SuccessfulPasswordResetLinkRequestResponse::class,
            IndistinguishablePasswordResetLinkResponse::class,
        );
        $this->app->bind(
            FailedPasswordResetLinkRequestResponse::class,
            IndistinguishablePasswordResetLinkResponse::class,
        );
        $this->app->bind(PasskeyRegistrationResponse::class, OpaquePasskeyRegistrationResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configurePasskeyRouteBinding();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Resolve package passkey routes by an opaque, owner-scoped handle.
     */
    private function configurePasskeyRouteBinding(): void
    {
        Route::bind('passkey', function (string $value): Passkey {
            $user = Auth::guard(Config::string('passkeys.guard'))->user();
            $passkey = $user instanceof PasskeyUser
                ? app(PasskeyRouteKey::class)->resolveFor($user, $value)
                : null;

            if (! $passkey instanceof Passkey) {
                throw (new ModelNotFoundException)->setModel(Passkey::class, [$value]);
            }

            return $passkey;
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Only active, administrator-provisioned accounts may authenticate.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('email', Str::lower((string) $request->input('email')))
                ->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            $leaseGuard = app(TeachingRoleAccessLeaseGuard::class);

            if ($leaseGuard->isRosterAccount($user)) {
                // Teaching-role credentials are short-lived operational leases.
                // They must never create a persistent remember-me credential.
                $request->merge(['remember' => false]);

                if (! $leaseGuard->allowsPassword(
                    $user,
                    (string) $request->input('password'),
                    $request->getHost(),
                )) {
                    return null;
                }
            } elseif ($user->status !== 'ACTIVE') {
                return null;
            }

            $user->forceFill(['last_login_at' => now()])->save();

            return $user;
        });

        Passkeys::authorizeLoginUsing(function (Request $request, PasskeyUser $user, Passkey $passkey): bool {
            return $user instanceof User
                && $user->status === 'ACTIVE'
                && ! app(TeachingRoleAccessLeaseGuard::class)->isRosterAccount($user);
        });

        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $leaseGuard = app(TeachingRoleAccessLeaseGuard::class);

            if (! $leaseGuard->isRosterAccount($event->user)) {
                return;
            }

            $fresh = $event->user->fresh();
            $request = app()->bound('request') ? request() : null;

            if (! $fresh instanceof User
                || ! $leaseGuard->allows($fresh, $request?->getHost())
                || ! $request instanceof Request
                || ! $request->hasSession()) {
                if ($request instanceof Request && $request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                throw new AuthenticationException(
                    'Teaching-role access lease is not valid.',
                    [$event->guard],
                );
            }

            $request->session()->put([
                TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY => (int) $fresh->teaching_access_epoch,
                TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY => (string) $fresh->teaching_access_lease_public_id,
            ]);
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
