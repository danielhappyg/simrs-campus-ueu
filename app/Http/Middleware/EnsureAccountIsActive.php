<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Authorization\TeachingRoleAccessException;
use App\Support\Authorization\TeachingRoleAccessLeaseGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function __construct(private readonly TeachingRoleAccessLeaseGuard $leaseGuard) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->leaseGuard->isRosterAccount($user)) {
            $fresh = $user->fresh();
            $session = $request->session();

            if (! $fresh instanceof User
                || ! $this->leaseGuard->sessionMatches(
                    $fresh,
                    $session->get(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY),
                    $session->get(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY),
                    $request->getHost(),
                )) {
                Auth::guard()->logout();
                $session->invalidate();
                $session->regenerateToken();

                abort(403, 'Teaching-role account access has ended. Contact the SIMRS Campus UEU facilitator.');
            }

            if ($request->isMethodSafe()) {
                return $next($request);
            }

            $sessionEpoch = $session->get(TeachingRoleAccessLeaseGuard::SESSION_EPOCH_KEY);
            $sessionLease = $session->get(TeachingRoleAccessLeaseGuard::SESSION_LEASE_KEY);
            try {
                return DB::transaction(function () use (
                    $request,
                    $next,
                    $fresh,
                    $sessionEpoch,
                    $sessionLease,
                ): Response {
                    $response = $next($request);
                    if (! $this->leaseGuard->mutationFence(
                        $fresh,
                        $sessionEpoch,
                        $sessionLease,
                        $request->getHost(),
                    )) {
                        throw new TeachingRoleAccessException(
                            'Teaching-role mutation fence rejected a stale access window.',
                        );
                    }

                    return $response;
                }, attempts: 1);
            } catch (TeachingRoleAccessException) {
                Auth::guard()->logout();
                $session->invalidate();
                $session->regenerateToken();

                abort(403, 'Teaching-role account access ended before the change could be saved. Please try again through the facilitator.');
            }
        }

        if ($user && $user->status !== 'ACTIVE') {
            abort(403, 'This account is inactive. Contact the SIMRS Campus UEU administrator.');
        }

        return $next($request);
    }
}
