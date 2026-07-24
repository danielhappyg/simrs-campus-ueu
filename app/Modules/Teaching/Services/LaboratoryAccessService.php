<?php

namespace App\Modules\Teaching\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditRecorder;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LaboratoryAccessService
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly ReservedDemoAccountRoster $roster,
    ) {}

    /**
     * @param  Collection<int, User>  $users
     * @return array{
     *   changed: bool,
     *   accountCount: int,
     *   sessionsRevoked: int,
     *   passkeysRemoved: int,
     *   resetTokensRemoved: int,
     *   passwordRotated: bool
     * }
     */
    public function transition(Collection $users, string $action, ?string $password = null): array
    {
        if (! in_array($action, ['enable', 'disable'], true)) {
            throw new DomainException('The laboratory access action is invalid.');
        }

        $userIds = $users
            ->map(fn (User $user): int => $user->id)
            ->values()
            ->all();
        $emails = $users
            ->map(fn (User $user): string => $user->email)
            ->values()
            ->all();
        $expectedEmails = $this->roster->emails();

        if ($users->count() !== ReservedDemoAccountRoster::EXPECTED_ACCOUNT_COUNT
            || count(array_unique($userIds)) !== ReservedDemoAccountRoster::EXPECTED_ACCOUNT_COUNT
            || collect($emails)->sort()->values()->all() !== collect($expectedEmails)->sort()->values()->all()) {
            throw new DomainException('The laboratory access transition requires the exact reserved roster.');
        }

        if ($action === 'enable' && (! is_string($password) || strlen($password) < 12)) {
            throw new DomainException('The temporary laboratory password configuration is invalid.');
        }

        $unchanged = $this->isAlreadyInTargetState($users, $action, $password)
            && ! $this->hasAuthenticationArtifacts($userIds, $emails);

        if ($unchanged) {
            return [
                'changed' => false,
                'accountCount' => $users->count(),
                'sessionsRevoked' => 0,
                'passkeysRemoved' => 0,
                'resetTokensRemoved' => 0,
                'passwordRotated' => false,
            ];
        }

        return DB::transaction(function () use ($users, $userIds, $emails, $action, $password): array {
            $sessionsRevoked = DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            $passkeysRemoved = DB::table('passkeys')->whereIn('user_id', $userIds)->delete();
            $resetTokensRemoved = DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();

            foreach ($users as $user) {
                $attributes = [
                    'status' => $action === 'enable' ? 'ACTIVE' : 'SUSPENDED',
                    'remember_token' => null,
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ];

                if ($action === 'enable') {
                    $attributes['password'] = $password;
                    $attributes['email_verified_at'] = $user->email_verified_at ?? now();
                }

                $user->forceFill($attributes)->save();
            }

            $this->auditRecorder->record(
                action: 'lab_access.'.$action.'d',
                resourceType: 'reserved_demo_account_roster',
                outcome: 'SUCCESS',
                metadata: [
                    'account_count' => $users->count(),
                    'sessions_revoked' => $sessionsRevoked,
                    'passkeys_removed' => $passkeysRemoved,
                    'reset_tokens_removed' => $resetTokensRemoved,
                    'password_rotated' => $action === 'enable',
                    'authentication_factors_cleared' => true,
                ],
                includeRequestFingerprint: false,
            );

            return [
                'changed' => true,
                'accountCount' => $users->count(),
                'sessionsRevoked' => $sessionsRevoked,
                'passkeysRemoved' => $passkeysRemoved,
                'resetTokensRemoved' => $resetTokensRemoved,
                'passwordRotated' => $action === 'enable',
            ];
        });
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function isAlreadyInTargetState(Collection $users, string $action, ?string $password): bool
    {
        return $users->every(function (User $user) use ($action, $password): bool {
            $baseReady = $user->remember_token === null
                && $user->two_factor_secret === null
                && $user->two_factor_recovery_codes === null
                && $user->two_factor_confirmed_at === null;

            if ($action === 'disable') {
                return $baseReady && $user->status === 'SUSPENDED';
            }

            return $baseReady
                && $user->status === 'ACTIVE'
                && $user->email_verified_at !== null
                && is_string($password)
                && Hash::check($password, $user->password);
        });
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $emails
     */
    private function hasAuthenticationArtifacts(array $userIds, array $emails): bool
    {
        return DB::table('sessions')->whereIn('user_id', $userIds)->exists()
            || DB::table('passkeys')->whereIn('user_id', $userIds)->exists()
            || DB::table('password_reset_tokens')->whereIn('email', $emails)->exists();
    }
}
