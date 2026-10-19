<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Events\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Events\Enums\FindingSeverity;
use RoundlyConsulting\Events\Support\Doctor;

/**
 * Checks the installation: key types, the lifecycle, tag types, tables, the distance query,
 * configured classes — and warns about what is worth a look. Exits non-zero on an error.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'events:doctor {--counts : Also compare every event\'s counters with a recount} {--json : Print the findings as JSON}';

    protected $description = 'Check the events installation';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run((bool) $this->option('counts'));
        $errors = array_filter($findings, static fn (DoctorFinding $finding): bool => $finding->severity === FindingSeverity::Error);

        if ($this->option('json') === true) {
            $this->line((string) json_encode(array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($findings === []) {
            $this->components->info('Everything looks right.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $finding->severity === FindingSeverity::Error
                ? $this->components->error('['.$finding->check.'] '.$finding->message)
                : $this->components->warn('['.$finding->check.'] '.$finding->message);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
