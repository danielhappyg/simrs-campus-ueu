<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

class ProtectTeachingRoleAuthenticationPaths
{
    /** @var list<string> */
    private const MANAGED_AUTHENTICATED_ROUTES = [
        'verification.verify',
        'verification.send',
        'profile.edit',
        'profile.update',
        'security.edit',
        'user-profile-information.update',
        'user-password.update',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'passkey.confirm-options',
        'passkey.confirm',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    public function __construct(private readonly TeachingRoleAccessLeaseGuard $leaseGuard) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('password.email')) {
            $requestLimited = $this->passwordResetRequestIsLimited($request);

            if ($requestLimited
                || $this->leaseGuard->isRosterAccount((string) $request->input('email'))) {
                // Rate limiting is deliberately evaluated before the roster fence.
                // Every suppressed request returns Fortify's generic public response.
                return $this->passwordResetLinkResponse($request);
            }
        }

        if (($request->routeIs('password.reset') || $request->routeIs('password.update'))
            && $this->leaseGuard->isRosterAccount((string) $request->input('email'))) {
            abort(403, 'Kredensial akun peran pengajaran dikelola oleh fasilitator.');
        }

        if (($request->routeIs('two-factor.login') || $request->routeIs('two-factor.login.store'))
            && $this->challengedRosterUser($request) instanceof User) {
            $request->session()->forget(['login.id', 'login.remember']);

            abort(403, 'Autentikasi dua faktor tidak tersedia untuk akun peran pengajaran.');
        }

        $user = $request->user();

        if ($user instanceof User
            && $user->status !== 'ACTIVE'
            && ! $this->leaseGuard->isRosterAccount($user)
            && $request->routeIs(...self::MANAGED_AUTHENTICATED_ROUTES)) {
            abort(403, 'Akun tidak aktif. Hubungi administrator SIMRS Campus UEU.');
        }

        if ($user instanceof User && $this->leaseGuard->isRosterAccount($user)) {
            $fresh = $user->fresh();

            if (! $fresh instanceof User
                || ! $this->leaseGuard->sessionMatches(
                    $fresh,
                    $request->session()->get(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY),
                    $request->session()->get(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY),
                    $request->getHost(),
                )) {
                Auth::guard()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, 'Akses akun peran pengajaran telah berakhir. Hubungi fasilitator SIMRS Campus UEU.');
            }

            if ($request->routeIs(...self::MANAGED_AUTHENTICATED_ROUTES)) {
                abort(403, 'Kredensial akun peran pengajaran dikelola oleh fasilitator.');
            }
        }

        $response = $next($request);
        $postResponseUser = $request->user();

        if ($postResponseUser instanceof User && $this->leaseGuard->isRosterAccount($postResponseUser)) {
            $fresh = $postResponseUser->fresh();

            if (! $fresh instanceof User
                || ! $this->leaseGuard->sessionMatches(
                    $fresh,
                    $request->session()->get(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY),
                    $request->session()->get(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY),
                    $request->getHost(),
                )) {
                Auth::guard()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, 'Akses akun peran pengajaran berakhir saat permintaan diproses. Hubungi fasilitator SIMRS Campus UEU.');
            }
        }

        return $response;
    }

    private function passwordResetRequestIsLimited(Request $request): bool
    {
        $normalizedEmail = Str::lower(trim((string) $request->input('email')));
        $ipAddress = (string) ($request->ip() ?? 'unknown');
        $decaySeconds = max(1, (int) config('fortify.password_reset_rate_limits.decay_seconds', 60));
        $emailIpLimit = max(1, (int) config('fortify.password_reset_rate_limits.email_ip_per_minute', 5));
        $ipLimit = max(1, (int) config('fortify.password_reset_rate_limits.ip_per_minute', 30));
        $emailIpKey = 'password-reset:email-ip:'.hash('sha256', $normalizedEmail."\0".$ipAddress);
        $ipKey = 'password-reset:ip:'.hash('sha256', $ipAddress);

        $emailIpAttempts = RateLimiter::hit($emailIpKey, $decaySeconds);
        $ipAttempts = RateLimiter::hit($ipKey, $decaySeconds);

        return $emailIpAttempts > $emailIpLimit || $ipAttempts > $ipLimit;
    }

    private function passwordResetLinkResponse(Request $request): Response
    {
        return app(SuccessfulPasswordResetLinkRequestResponse::class, [
            'status' => Password::RESET_LINK_SENT,
        ])->toResponse($request);
    }

    private function challengedRosterUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $user = User::query()->find((int) $id);

        return $user instanceof User && $this->leaseGuard->isRosterAccount($user)
            ? $user
            : null;
    }
}
