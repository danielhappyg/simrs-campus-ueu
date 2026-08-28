<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

final class IndistinguishablePasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'Jika alamat email terdaftar dan memenuhi syarat, tautan pengaturan ulang akan dikirim.';

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => self::MESSAGE]);
        }

        return back()->with('status', self::MESSAGE);
    }
}
