<?php

namespace App\Support\Authentication;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;

final class PasskeyRouteKey
{
    /**
     * Return a non-reversible browser identifier for a passkey.
     */
    public function for(Passkey $passkey): string
    {
        $encrypted = Crypt::encryptString('simrs-passkey-route|'.$passkey->getKey());

        return rtrim(strtr($encrypted, '+/', '-_'), '=');
    }

    /**
     * Resolve an opaque route key only among the authenticated user's passkeys.
     */
    public function resolveFor(PasskeyUser $user, string $routeKey): ?Passkey
    {
        if (! preg_match('/\A[A-Za-z0-9_-]+\z/D', $routeKey)) {
            return null;
        }

        $encoded = strtr($routeKey, '-_', '+/');
        $remainder = strlen($encoded) % 4;

        if ($remainder === 1) {
            return null;
        }

        if ($remainder > 1) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }

        try {
            $payload = Crypt::decryptString($encoded);
        } catch (DecryptException) {
            return null;
        }

        $prefix = 'simrs-passkey-route|';
        $key = str_starts_with($payload, $prefix) ? substr($payload, strlen($prefix)) : '';

        if ($key === '' || ! ctype_digit($key)) {
            return null;
        }

        $passkey = $user->passkeys()->whereKey($key)->first();

        return $passkey instanceof Passkey ? $passkey : null;
    }
}
