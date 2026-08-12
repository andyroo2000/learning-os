<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\PruneTerminalContentGenerationRequestsAction;
use Illuminate\Console\Command;

final class PruneTerminalContentGenerationRequests extends Command
{
    protected $signature = 'content:prune-generation-requests
        {--dry-run : Report eligible requests without deleting them}
        {--limit=500 : Maximum number of terminal generation requests to inspect}';

    protected $description = 'Delete completed and failed generation request ledgers after their replay window.';

    public function handle(PruneTerminalContentGenerationRequestsAction $prune): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5000],
        ]);

        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 5000.');

            return self::INVALID;
        }

        $result = $prune->handle(
            dryRun: (bool) $this->option('dry-run'),
            limit: $limit,
        );
        $this->line(sprintf(
            '%s: %d candidate(s), %d deleted, %d skipped, %d failed.',
            $result->dryRun ? 'Dry run completed' : 'Prune completed',
            $result->candidates,
            $result->deleted,
            $result->skipped,
            $result->failed,
        ));

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
