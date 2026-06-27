<?php

namespace App\Console\Commands;

use App\Support\Rehearsal\DatabaseRehearsalSmokeCheck;
use Illuminate\Console\Command;

class RunDatabaseRehearsalSmokeCheck extends Command
{
    protected $signature = 'rehearsal:smoke
        {--user-email= : Existing user email to authenticate read-oriented smoke requests as}
        {--json : Output the smoke report as JSON}';

    protected $description = 'Smoke-test a restored Convo Lab database through the Learning OS API.';

    public function handle(DatabaseRehearsalSmokeCheck $smokeCheck): int
    {
        $report = $smokeCheck->run($this->option('user-email'));

        if ($this->option('json')) {
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $this->output->writeln($encoded !== false ? $encoded : '{"error":"Report could not be JSON-encoded"}');

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Database rehearsal smoke check');
        $this->line(sprintf(
            'Connection: %s%s',
            $report['connection']['name'],
            $report['connection']['database'] !== null ? ' / '.$report['connection']['database'] : '',
        ));
        $this->newLine();

        foreach ($report['checks'] as $check) {
            $this->line(sprintf(
                '[%s] %s - %s',
                $check['ok'] ? 'PASS' : 'FAIL',
                $check['name'],
                $check['message'],
            ));

            if (array_key_exists('meta', $check)) {
                $this->line('       '.json_encode($check['meta'], JSON_UNESCAPED_SLASHES));
            }
        }

        $this->newLine();
        $report['ok']
            ? $this->info('Smoke check passed.')
            : $this->error('Smoke check failed.');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
