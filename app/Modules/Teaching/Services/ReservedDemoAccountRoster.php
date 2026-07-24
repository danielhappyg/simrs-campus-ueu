<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Teaching\Enums\EnvironmentMode;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Models\SimulationSession;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class ReservedDemoAccountRoster
{
    public const EXPECTED_ACCOUNT_COUNT = 10;

    /**
     * @return list<string>
     */
    public function emails(): array
    {
        $configured = config('simulation.demo_account_emails');

        if (! is_array($configured)
            || count($configured) !== self::EXPECTED_ACCOUNT_COUNT
            || array_is_list($configured) === false
            || collect($configured)->contains(
                fn (mixed $email): bool => ! is_string($email)
                    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                    || ! str_ends_with($email, '@example.invalid'),
            )
            || collect($configured)->unique()->count() !== self::EXPECTED_ACCOUNT_COUNT) {
            throw new DomainException('The reserved synthetic demo-account roster configuration is invalid.');
        }

        /** @var list<string> $configured */
        return $configured;
    }

    /**
     * Resolve the exact configured roster through the retained synthetic source.
     *
     * @return Collection<int, User>
     */
    public function usersForSource(string $sourceCode): Collection
    {
        $emails = $this->emails();
        $source = SimulationSession::query()
            ->with('scenario')
            ->where('code', $sourceCode)
            ->first();

        if (! $source
            || $source->source_session_id !== null
            || $source->environment_mode !== EnvironmentMode::Simulation
            || $source->scenario->code !== 'OPD-REF-001'
            || $source->scenario->version !== 1) {
            throw new DomainException('The retained synthetic reference source is unavailable.');
        }

        $users = User::query()
            ->whereIn('email', $emails)
            ->orderBy('id')
            ->get();
        $assignmentUserIds = Assignment::query()
            ->where('session_id', $source->getKey())
            ->pluck('user_id')
            ->unique()
            ->sort()
            ->values();

        if ($users->count() !== self::EXPECTED_ACCOUNT_COUNT
            || $users->pluck('email')->unique()->count() !== self::EXPECTED_ACCOUNT_COUNT
            || $assignmentUserIds->count() !== self::EXPECTED_ACCOUNT_COUNT
            || $assignmentUserIds->all() !== $users->pluck('id')->sort()->values()->all()) {
            throw new DomainException('The retained source does not match the reserved synthetic account roster.');
        }

        return $users;
    }
}
