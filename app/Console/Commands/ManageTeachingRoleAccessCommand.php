<?php

namespace App\Console\Commands;

use App\Support\Authorization\TeachingRoleAccessException;
use App\Support\Authorization\TeachingRoleAccessManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ManageTeachingRoleAccessCommand extends Command
{
    protected $signature = 'teaching:role-access
                            {action : Exactly one of status, activate, revoke}
                            {email : Exactly one allowlisted demo-account email}
                            {--confirm= : Exact action-specific confirmation phrase}
                            {--operator= : Accountable operator or change authority}
                            {--reason= : Specific operational reason}
                            {--expected-environment= : Exact trusted runtime environment identifier}
                            {--expected-release-sha= : Exact trusted runtime release SHA}
                            {--expected-deployment-url= : Exact trusted Vercel deployment URL host}
                            {--expected-canonical-host= : Exact approved canonical application host}
                            {--ttl-minutes= : Activation lease duration in minutes}';

    protected $description = 'Inspect, activate, or revoke exactly one temporary hosted teaching-role account';

    public function handle(TeachingRoleAccessManager $manager): int
    {
        try {
            $result = $manager->execute(
                action: (string) $this->argument('action'),
                email: (string) $this->argument('email'),
                confirmation: $this->stringOption('confirm'),
                operator: $this->stringOption('operator'),
                reason: $this->stringOption('reason'),
                expectedEnvironment: $this->stringOption('expected-environment'),
                expectedReleaseSha: $this->stringOption('expected-release-sha'),
                expectedDeploymentUrl: $this->stringOption('expected-deployment-url'),
                expectedCanonicalHost: $this->stringOption('expected-canonical-host'),
                ttlMinutes: $this->integerOption('ttl-minutes'),
            );
        } catch (InvalidArgumentException|TeachingRoleAccessException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::critical('Teaching-role access command failed.', [
                'exception_class' => $exception::class,
                'failure_fingerprint' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ]);
            $this->error('Teaching-role access failed safely. Review protected application logs for diagnostics.');

            return self::FAILURE;
        }

        $this->line('Target: '.$result['target_email']);

        foreach ($result['roster'] as $state) {
            $this->line(sprintf(
                '%s roles=%s expected=%s status=%s verified=%s admin=%s remember=%s mfa=%s sessions=%d passkeys=%d resets=%d lease=%s invariant=%s',
                $state['email'],
                $state['roles'] === [] ? 'none' : implode(',', $state['roles']),
                $state['expected_role'],
                $state['status'],
                $state['verified'] ? 'yes' : 'no',
                $state['is_system_administrator'] ? 'yes' : 'no',
                $state['remember_present'] ? 'yes' : 'no',
                $state['mfa_present'] ? 'yes' : 'no',
                $state['sessions'],
                $state['passkeys'],
                $state['reset_records'],
                $state['lease_valid'] ? 'valid' : 'closed',
                $state['invariant_ok'] ? 'OK' : 'DRIFT',
            ));
        }

        $aggregate = $result['aggregate'];
        $this->line(sprintf(
            'Roster: active=%d disabled=%d missing=%d drifted=%d sessions=%d passkeys=%d resets=%d',
            $aggregate['active'],
            $aggregate['disabled'],
            $aggregate['missing'],
            $aggregate['drifted'],
            $aggregate['sessions'],
            $aggregate['passkeys'],
            $aggregate['reset_records'],
        ));

        if ($result['action'] !== TeachingRoleAccessManager::ACTION_STATUS) {
            $this->info(sprintf(
                'Result: %s completed (mutated=%s, idempotent=%s, audit=%s).',
                strtoupper($result['action']),
                $result['mutated'] ? 'yes' : 'no',
                $result['idempotent'] ? 'yes' : 'no',
                $result['audit_recorded'] ? 'recorded' : 'missing',
            ));
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }
}
