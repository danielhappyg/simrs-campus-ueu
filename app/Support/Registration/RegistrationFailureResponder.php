<?php

namespace App\Support\Registration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Keep teaching desks usable: surface unexpected store failures instead of a bare 500.
 */
final class RegistrationFailureResponder
{
    public static function redirect(Request $request, Throwable $exception): ?RedirectResponse
    {
        if ($exception instanceof ValidationException
            || $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof HttpExceptionInterface
        ) {
            return null;
        }

        report($exception);
        error_log(sprintf(
            '[simrs] registration store failed: %s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ));

        if (config('simulation.synthetic_only') !== true) {
            return null;
        }

        $message = sprintf(
            'Pendaftaran gagal (simulasi): %s — %s',
            class_basename($exception),
            Str::limit($exception->getMessage(), 240),
        );

        return redirect()
            ->back()
            ->withInput($request->except(['password', 'password_confirmation']))
            ->with('error', $message);
    }
}
