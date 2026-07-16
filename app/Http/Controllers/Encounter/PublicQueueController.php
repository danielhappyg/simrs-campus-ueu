<?php

namespace App\Http\Controllers\Encounter;

use App\Http\Controllers\Controller;
use App\Modules\Encounter\Enums\QueueEventStatus;
use App\Modules\Encounter\Models\QueueEvent;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Enums\SessionStatus;
use App\Modules\Teaching\Models\SimulationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicQueueController extends Controller
{
    public function __invoke(SimulationSession $session): JsonResponse
    {
        abort_unless(
            $session->environment_mode === EnvironmentMode::Simulation
                && $session->status === SessionStatus::Active,
            404,
        );

        $entries = QueueEvent::query()
            ->whereHas('encounter', fn ($query) => $query->where('session_id', $session->getKey()))
            ->whereIn('status', [
                QueueEventStatus::Waiting->value,
                QueueEventStatus::Called->value,
                QueueEventStatus::InService->value,
            ])
            ->with('encounter.patient')
            ->orderBy('started_at')
            ->limit(100)
            ->get()
            ->map(fn (QueueEvent $event): array => [
                'ticket' => $event->ticket_number,
                'cue' => $this->maskedCue($event->encounter->patient->full_name),
                'status' => $event->status->label(),
            ])
            ->values();

        return response()->json([
            'simulation' => true,
            'label' => 'SIMULASI — DATA SINTETIS',
            'session' => $session->code,
            'entries' => $entries,
        ]);
    }

    private function maskedCue(string $name): string
    {
        $tokens = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->values();
        $initials = $tokens->take(3)->map(fn (string $token): string => Str::upper(Str::substr($token, 0, 1)).'.');

        return $initials->join(' ');
    }
}
