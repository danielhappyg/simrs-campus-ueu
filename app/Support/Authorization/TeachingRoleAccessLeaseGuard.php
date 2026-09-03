<?php

namespace App\Support\Authorization;

use App\Models\TeachingRoleAccessLease;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TeachingRoleAccessLeaseGuard
{
    public const SESSION_EPOCH_KEY = 'teaching_role_access_epoch';

    public const SESSION_LEASE_KEY = 'teaching_role_access_lease';

    public function isRosterAccount(User|string $user): bool
    {
        if ($user instanceof User) {
            $email = strtolower((string) $user->email);
            $rosterKey = $user->teaching_access_roster_key;

            return array_key_exists($email, TeachingRoleAccessManager::ROSTER)
                || (is_string($rosterKey)
                    && in_array($rosterKey, array_values(TeachingRoleAccessManager::ROSTER), true));
        }

        $email = strtolower(trim($user));

        return array_key_exists($email, TeachingRoleAccessManager::ROSTER);
    }

    public function allows(User $user, ?string $requestHost = null): bool
    {
        if (! $this->isRosterAccount($user)) {
            return true;
        }

        $fresh = User::query()->find($user->getKey());
        if (! $fresh instanceof User) {
            return false;
        }

        $effectiveRoster = TeachingRoleAccessManager::effectiveRoster();
        $expectedEmail = strtolower((string) $fresh->email);
        $expectedRole = $effectiveRoster[$expectedEmail] ?? null;
        $expectedEmail = $expectedRole === null
            ? null
            : array_search($expectedRole, $effectiveRoster, true);
        if ($fresh->status !== 'TEACHING_ACTIVE'
            || $fresh->is_system_administrator !== false
            || $expectedRole === null
            || ! is_string($expectedEmail)
            || strtolower((string) $fresh->email) !== $expectedEmail
            || $fresh->teaching_access_roster_key !== $expectedRole
            || $fresh->roleSlugs() !== [$expectedRole]
            || $fresh->teaching_access_lease_public_id === null
            || $fresh->teaching_access_expires_at_epoch === null
            || $fresh->teaching_access_epoch < 1) {
            return false;
        }

        $activeLeases = TeachingRoleAccessLease::query()
            ->where('user_id', $fresh->getKey())
            ->where('status', 'ACTIVE')
            ->get();
        $lease = $activeLeases->count() === 1 ? $activeLeases->first() : null;

        if (! $lease instanceof TeachingRoleAccessLease
            || $lease->status !== 'ACTIVE'
            || $lease->active_slot !== 1
            || $lease->public_id !== $fresh->teaching_access_lease_public_id
            || $lease->expected_role !== $expectedRole
            || ! hash_equals(
                $lease->password_state_commitment,
                $this->passwordStateCommitment((string) $fresh->password),
            )
            || $lease->expires_at_epoch <= $this->databaseEpoch()
            || $lease->expires_at_epoch !== $fresh->teaching_access_expires_at_epoch
            || $lease->environment !== $this->runtimeEnvironment()
            || $lease->release_sha !== $this->runtimeReleaseSha()
            || $lease->deployment_url !== $this->runtimeDeploymentUrl()
            || $lease->canonical_host !== $this->runtimeCanonicalHost()
            || ($requestHost !== null && strtolower($requestHost) !== $this->runtimeCanonicalHost())) {
            return false;
        }

        return true;
    }

    public function allowsPassword(User $user, string $password, ?string $requestHost = null): bool
    {
        if (! $this->allows($user, $requestHost)) {
            return false;
        }

        $fresh = $user->fresh();
        if (! $fresh instanceof User || ! is_string($fresh->teaching_access_lease_public_id)) {
            return false;
        }

        $lease = TeachingRoleAccessLease::query()
            ->where('public_id', $fresh->teaching_access_lease_public_id)
            ->where('user_id', $user->getKey())
            ->first();

        return $lease instanceof TeachingRoleAccessLease
            && hash_equals($lease->credential_commitment, $this->credentialCommitment($password));
    }

    public function sessionMatches(User $user, mixed $epoch, mixed $leasePublicId, ?string $requestHost = null): bool
    {
        if (! $this->isRosterAccount($user)) {
            return true;
        }

        $fresh = $user->fresh();

        return $fresh instanceof User
            && is_int($epoch)
            && is_string($leasePublicId)
            && $epoch === (int) $fresh->teaching_access_epoch
            && hash_equals((string) $fresh->teaching_access_lease_public_id, $leasePublicId)
            && $this->allows($fresh, $requestHost);
    }

    public function mutationFence(
        User $user,
        mixed $epoch,
        mixed $leasePublicId,
        string $requestHost,
    ): bool {
        if (! $this->isRosterAccount($user) || ! is_int($epoch) || ! is_string($leasePublicId)) {
            return false;
        }

        $fresh = User::query()->whereKey($user->getKey())->sharedLock()->first();
        if (! $fresh instanceof User) {
            return false;
        }

        $expectedEmail = strtolower((string) $fresh->email);
        $expectedRole = TeachingRoleAccessManager::effectiveRoster()[$expectedEmail] ?? null;
        $activeLeases = TeachingRoleAccessLease::query()
            ->where('user_id', $fresh->getKey())
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->sharedLock()
            ->get();
        $lease = $activeLeases->count() === 1 ? $activeLeases->first() : null;
        $nowEpoch = $this->databaseWallClockEpoch();

        return $expectedRole !== null
            && $fresh->status === 'TEACHING_ACTIVE'
            && $fresh->is_system_administrator === false
            && $fresh->teaching_access_roster_key === $expectedRole
            && $fresh->roleSlugs() === [$expectedRole]
            && $epoch === (int) $fresh->teaching_access_epoch
            && hash_equals((string) $fresh->teaching_access_lease_public_id, $leasePublicId)
            && $lease instanceof TeachingRoleAccessLease
            && $lease->active_slot === 1
            && $lease->public_id === $leasePublicId
            && $lease->expected_role === $expectedRole
            && $lease->expires_at_epoch > $nowEpoch
            && $lease->expires_at_epoch === $fresh->teaching_access_expires_at_epoch
            && hash_equals(
                $lease->password_state_commitment,
                $this->passwordStateCommitment((string) $fresh->password),
            )
            && $lease->environment === $this->runtimeEnvironment()
            && $lease->release_sha === $this->runtimeReleaseSha()
            && $lease->deployment_url === $this->runtimeDeploymentUrl()
            && $lease->canonical_host === $this->runtimeCanonicalHost()
            && strtolower($requestHost) === $this->runtimeCanonicalHost();
    }

    public function credentialCommitment(string $password): string
    {
        return hash_hmac('sha256', "teaching-role-credential\0{$password}", $this->commitmentKey());
    }

    public function passwordStateCommitment(string $passwordHash): string
    {
        return hash_hmac('sha256', "teaching-role-password-state\0{$passwordHash}", $this->commitmentKey());
    }

    /** @return array{environment: string, release_sha: string, deployment_url: string, canonical_host: string} */
    public function runtimeBindings(): array
    {
        return [
            'environment' => $this->runtimeEnvironment(),
            'release_sha' => $this->runtimeReleaseSha(),
            'deployment_url' => $this->runtimeDeploymentUrl(),
            'canonical_host' => $this->runtimeCanonicalHost(),
        ];
    }

    public function databaseEpoch(): int
    {
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS db_clock_value');
        $value = is_object($row) ? data_get($row, 'db_clock_value') : null;

        if (! is_string($value)) {
            throw new RuntimeException('Teaching-role access could not obtain authoritative database time.');
        }

        $applicationTimezone = config('app.timezone');

        return CarbonImmutable::parse(
            $value,
            is_string($applicationTimezone) ? $applicationTimezone : 'UTC',
        )->getTimestamp();
    }

    public function databaseWallClockEpoch(): int
    {
        $expression = DB::connection()->getDriverName() === 'pgsql'
            ? 'clock_timestamp()'
            : 'CURRENT_TIMESTAMP';
        $row = DB::selectOne("SELECT {$expression} AS db_clock_value");
        $value = is_object($row) ? data_get($row, 'db_clock_value') : null;
        if (! is_string($value)) {
            throw new RuntimeException('Teaching-role access could not obtain authoritative wall-clock database time.');
        }

        $applicationTimezone = config('app.timezone');

        return CarbonImmutable::parse(
            $value,
            is_string($applicationTimezone) ? $applicationTimezone : 'UTC',
        )->getTimestamp();
    }

    private function commitmentKey(): string
    {
        $key = config('simulation.teaching_role_access_commitment_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Teaching-role access requires a dedicated commitment key of at least 32 bytes.');
        }

        return $key;
    }

    private function runtimeEnvironment(): string
    {
        $environment = config('simulation.teaching_role_access_environment');

        if (! is_string($environment)
            || preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $environment) !== 1) {
            throw new RuntimeException('Teaching-role access requires a bounded runtime environment identifier.');
        }

        return $environment;
    }

    private function runtimeReleaseSha(): string
    {
        $sha = config('simulation.teaching_role_access_release_sha');

        if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/', $sha) !== 1) {
            throw new RuntimeException('Teaching-role access requires an exact 40-character runtime release SHA.');
        }

        return $sha;
    }

    private function runtimeDeploymentUrl(): string
    {
        $value = strtolower(trim((string) config('simulation.teaching_role_access_deployment_url')));
        if (preg_match('/\A[a-z0-9](?:[a-z0-9.-]{1,251}[a-z0-9])?\z/', $value) !== 1) {
            throw new RuntimeException('Teaching-role access requires the exact deployment URL host.');
        }

        return $value;
    }

    private function runtimeCanonicalHost(): string
    {
        $value = strtolower(trim((string) config('simulation.teaching_role_access_canonical_host')));
        if (preg_match('/\A[a-z0-9](?:[a-z0-9.-]{1,251}[a-z0-9])?\z/', $value) !== 1) {
            throw new RuntimeException('Teaching-role access requires the exact canonical host.');
        }

        return $value;
    }
}
