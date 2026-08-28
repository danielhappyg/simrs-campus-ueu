<?php

namespace App\Http\Responses;

use App\Support\Authentication\PasskeyRouteKey;
use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Laravel\Passkeys\Passkey;
use Symfony\Component\HttpFoundation\Response;

final class OpaquePasskeyRegistrationResponse implements PasskeyRegistrationResponseContract
{
    private ?Passkey $passkey = null;

    public function __construct(private readonly PasskeyRouteKey $routeKeys) {}

    public function withPasskey(Passkey $passkey): static
    {
        $this->passkey = $passkey;

        return $this;
    }

    public function toResponse($request): Response
    {
        if (! $request->wantsJson()) {
            return back()->with('status', 'passkey-registered');
        }

        $data = ['status' => 'passkey-registered'];

        if ($this->passkey instanceof Passkey) {
            $data['id'] = $this->routeKeys->for($this->passkey);
            $data['name'] = $this->passkey->name;
        }

        return new JsonResponse($data);
    }
}
