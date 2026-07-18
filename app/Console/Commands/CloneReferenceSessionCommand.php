<?php

namespace App\Console\Commands;

use App\Modules\Teaching\Services\ReferenceSessionCloneService;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class CloneReferenceSessionCommand extends Command
{
    protected $signature = 'simulation:clone-reference-session
        {code : New unique uppercase session code}
        {--source=SIM-RJ-UEU-001 : Pristine synthetic source-session code}
        {--duration=480 : Active duration in minutes (30–10,080)}
        {--appointment-offset=15 : Appointment minutes from session start (-1,440 through duration)}
        {--json : Emit a machine-readable identity-minimized summary}';

    protected $description = 'Clone a pristine synthetic outpatient reference session for an isolated rehearsal';

    public function handle(ReferenceSessionCloneService $service): int
    {
        try {
            $duration = filter_var($this->option('duration'), FILTER_VALIDATE_INT);

            if ($duration === false) {
                throw new DomainException('Clone duration must be an integer number of minutes.');
            }

            $appointmentOffset = filter_var($this->option('appointment-offset'), FILTER_VALIDATE_INT);

            if ($appointmentOffset === false) {
                throw new DomainException('Appointment offset must be an integer number of minutes.');
            }

            $summary = $service->createClone(
                sourceCode: (string) $this->option('source'),
                targetCode: (string) $this->argument('code'),
                durationMinutes: $duration,
                appointmentOffsetMinutes: $appointmentOffset,
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The reference session could not be cloned. No partial target graph was retained.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->info('A fresh synthetic outpatient reference session is ready for an isolated rehearsal.');
        $this->table(
            ['Source', 'New session', 'Status', 'Encounter', 'Encounter status'],
            [[
                $summary['sourceSession']['code'],
                $summary['session']['code'],
                $summary['session']['status'],
                $summary['encounter']['number'],
                $summary['encounter']['status'],
            ]],
        );
        $this->table(
            ['Assignments', 'Initial tasks', 'Stock lots', 'Progressed state copied'],
            [[
                $summary['counts']['assignments'],
                $summary['counts']['tasks'],
                $summary['counts']['stockLots'],
                $summary['progressedStateCopied'] ? 'yes' : 'no',
            ]],
        );
        $this->warn('Synthetic rehearsal only. This command does not reset, accept, merge, deploy, or authorize a session.');

        return self::SUCCESS;
    }
}
