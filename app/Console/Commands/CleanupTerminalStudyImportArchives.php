<?php

namespace App\Console\Commands;

use App\Domain\Study\Actions\CleanupTerminalStudyImportArchivesAction;
use Illuminate\Console\Command;

final class CleanupTerminalStudyImportArchives extends Command
{
    protected $signature = 'study:prune-import-archives
        {--dry-run : Report eligible archives without deleting or updating cleanup markers}
        {--limit=500 : Maximum number of terminal import jobs to inspect}';

    protected $description = 'Delete retained source archives from completed and failed study imports.';

    public function handle(CleanupTerminalStudyImportArchivesAction $cleanup): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5000],
        ]);

        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 5000.');

            return self::INVALID;
        }

        $result = $cleanup->handle(
            dryRun: (bool) $this->option('dry-run'),
            limit: $limit,
        );
        $this->line(sprintf(
            '%s: %d candidate(s), %d deleted, %d already missing, %d failed (%d unsafe).',
            $result->dryRun ? 'Dry run completed' : 'Cleanup completed',
            $result->candidates,
            $result->deleted,
            $result->alreadyMissing,
            $result->failed,
            $result->unsafe,
        ));

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
