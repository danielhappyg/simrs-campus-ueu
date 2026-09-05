<?php

namespace App\Http\Controllers\Emergency\Concerns;

use App\Support\Emergency\EmergencyAuditUnavailable;
use App\Support\Emergency\EmergencyDenied;
use Illuminate\Http\RedirectResponse;

trait RespondsToEmergencyMutation
{
    private function emergencyMutation(callable $mutation, string $successMessage): RedirectResponse
    {
        try {
            $mutation();
        } catch (EmergencyAuditUnavailable $exception) {
            abort(503, __($exception->getMessage()));
        } catch (EmergencyDenied $exception) {
            return back()
                ->withErrors(['emergency' => __($exception->getMessage())])
                ->withInput();
        }

        return back()->with('success', $successMessage);
    }
}
