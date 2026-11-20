<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Support\JournalUsage;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Domain\Study\Services\StudyCaptureReadingGenerator;
use App\Support\Japanese\BracketFurigana;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ProcessJournalReading
{
    public function __construct(private readonly StudyCaptureReadingGenerator $readings) {}

    public function handle(string $analysisId, string $readingId): void
    {
        $analysis = JournalAnalysis::find($analysisId);
        $state = $analysis?->result['documentReadings'][$readingId] ?? null;
        if (($state['status'] ?? null) !== 'pending' || $analysis->entry === null) {
            return;
        }
        $reading = JournalUsage::entry($analysis->entry, 'journal.edit_reading', fn () => $this->readings->generate($state['text']));
        BracketFurigana::validate($state['text'], $reading);
        $this->finish($analysisId, $readingId, 'ready', $reading);
    }

    public function fail(string $analysisId, string $readingId): void
    {
        $this->finish($analysisId, $readingId, 'failed', null);
    }

    private function finish(string $analysisId, string $readingId, string $status, ?string $reading): void
    {
        $entry = JournalAnalysis::find($analysisId)?->entry;
        if ($entry === null) {
            return;
        }
        try {
            JournalWriteTransaction::run($entry->user_id, static function () use ($analysisId, $readingId, $status, $reading): void {
                $analysis = JournalAnalysis::lockForUpdate()->find($analysisId);
                $states = $analysis?->result['documentReadings'] ?? [];
                if (($states[$readingId]['status'] ?? null) !== 'pending') {
                    return;
                }
                $states[$readingId] = [...$states[$readingId], 'status' => $status, 'reading' => $reading];
                // Enrichment never writes document text or advances its editing/audio revision.
                $analysis->result = [...$analysis->result, 'documentReadings' => $states];
                $analysis->save();
            });
        } catch (ModelNotFoundException) {
            // Account deletion can win the race before the owner lock is acquired.
        }
    }
}
