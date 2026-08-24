<?php

namespace App\Console\Commands;

use App\Support\Authorization\RebuildAdminRoleReconciler;
use Illuminate\Console\Command;
use Throwable;

class ReconcileRebuildAdminCommand extends Command
{
    protected $signature = 'rebuild:admin-reconcile
                            {--apply : Apply the reconciliation; without this flag the command is a dry run}
                            {--disable : Also disable the rebuild-admin account for containment}
                            {--operator= : Person or change authority accountable for an applied operation}
                            {--reason= : Specific reason for an applied operation}';

    protected $description = 'Safely reconcile the configured rebuild-admin account to its canonical admin-only role';

    public function handle(RebuildAdminRoleReconciler $reconciler): int
    {
        $apply = (bool) $this->option('apply');

        try {
            $result = $reconciler->reconcile(
                apply: $apply,
                disable: (bool) $this->option('disable'),
                operator: $this->stringOption('operator'),
                reason: $this->stringOption('reason'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: '.($apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('Target: '.$result['target_email'].' ('.$result['target_public_id'].')');
        $this->line('Roles: '.implode(', ', $result['before_roles']).' -> '.implode(', ', $result['after_roles']));
        $this->line('Status: '.$result['before_status'].' -> '.$result['after_status']);
        $this->line('Sessions found: '.$result['sessions_found']);

        if (! $apply) {
            $this->warn('Dry run only: no roles, status, sessions, or audit records were changed.');
            $this->line('Pass --apply with non-placeholder --operator and --reason values to mutate.');
        } else {
            $this->line('Sessions revoked: '.$result['sessions_revoked']);

            if ($result['mutated']) {
                $this->info('Rebuild admin reconciliation applied and audit evidence recorded.');
            } else {
                $this->info('Already canonical: no role, status, or session mutation was required; audit evidence recorded.');
            }
        }

        $this->warn('System-admin capability bypass remains active because is_system_administrator stays true.');
        $this->line('No password values were changed.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
