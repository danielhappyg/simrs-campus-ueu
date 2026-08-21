<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'environment' => [
                'mode' => config('simulation.mode'),
                'syntheticOnly' => config('simulation.synthetic_only'),
                'banner' => config('simulation.banner'),
                'restriction' => config('simulation.restriction'),
            ],
            'auth' => [
                'user' => $user instanceof User ? [
                    'publicId' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
                    'status' => $user->status,
                    'isSystemAdministrator' => $user->is_system_administrator,
                    'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                ] : null,
                'roles' => $user instanceof User ? $user->roleSlugs() : [],
                'capabilities' => $user instanceof User ? $user->capabilityList() : [],
            ],
            'requestId' => $request->attributes->get('request_id'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
