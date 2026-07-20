<?php

namespace App\Console\Commands;

use App\Domain\Study\Actions\RepairLegacyStudyMediaReferencesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RepairLegacyStudyMediaReferences extends Command
{
    protected $signature = 'study:repair-legacy-media-references
        {--apply : Persist repairs; without this option the command performs a dry run}';

    protected $description = 'Replace stale Convo Lab media UUIDs in card payloads with linked Learning OS media ULIDs.';

    public function handle(RepairLegacyStudyMediaReferencesAction $repair): int
    {
        $apply = (bool) $this->option('apply');

        try {
            $result = $repair->handle(DB::connection(), $apply);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s: %d linked cards scanned, %d cards changed, %d references changed.',
            $apply ? 'Repair completed' : 'Dry run completed',
            $result->cardsScanned,
            $result->cardsChanged,
            $result->referencesChanged,
        ));
        $this->line(sprintf(
            '%d stale references were unmatched; %d were ambiguous and left unchanged.',
            $result->unmatchedReferences,
            $result->ambiguousReferences,
        ));

        return self::SUCCESS;
    }
}
