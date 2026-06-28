<?php

namespace App\Console\Commands;

use App\Support\Rehearsal\DatabaseRehearsalSmokeCheck;
use Illuminate\Console\Command;

class RunDatabaseRehearsalSmokeCheck extends Command
{
    protected $signature = 'rehearsal:smoke
        {--user-email= : Existing user email to authenticate read-oriented smoke requests as}
        {--json : Output the smoke report as JSON}
        {--allow-production : Permit the smoke check to run when APP_ENV=production}';

    protected $description = 'Smoke-test a restored Convo Lab database through the Learning OS API.';

    public function handle(DatabaseRehearsalSmokeCheck $smokeCheck): int
    {
        if (app()->isProduction() && ! $this->option('allow-production')) {
            $this->error('This command must not run in production without --allow-production.');

            return self::FAILURE;
        }

        if (app()->isProduction()) {
            $this->warn('WARNING: Running smoke check against a production database.');
        }

        $report = $smokeCheck->run($this->option('user-email'));

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

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
                $this->line('       '.json_encode($check['meta'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            }
        }

        $this->newLine();
        $report['ok']
            ? $this->info('Smoke check passed.')
            : $this->error('Smoke check failed.');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
