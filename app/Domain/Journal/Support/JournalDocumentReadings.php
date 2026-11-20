<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalReply;
use App\Support\Japanese\BracketFurigana;
use Illuminate\Support\Str;

/** Reading records outlive document snapshots so replies and undo can retain their readings. */
final class JournalDocumentReadings
{
    public static function reserve(JournalAnalysis $analysis, string $text): ?string
    {
        if (! BracketFurigana::needsReading($text)) {
            return null;
        }
        $id = (string) Str::ulid();
        $readings = $analysis->result['documentReadings'] ?? [];
        $readings[$id] = ['text' => $text, 'status' => 'pending', 'reading' => null, 'queuedAt' => now()->toISOString()];
        $analysis->result = [...$analysis->result, 'documentReadings' => $readings];

        return $id;
    }

    public static function resolve(JournalAnalysis $analysis, array $blocks): array
    {
        return array_map(static function (array $block) use ($analysis): array {
            $state = self::state($analysis, $block);
            if ($state['status'] === 'ready') {
                $block['reading'] = $state['reading'] ?? $block['reading'];
            }

            return $block;
        }, $blocks);
    }

    public static function status(JournalAnalysis $analysis, array $blocks): string
    {
        $statuses = array_map(fn ($block) => self::state($analysis, $block)['status'], $blocks);
        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }

        return in_array('failed', $statuses, true) ? 'failed' : 'ready';
    }

    /** Caller holds the analysis write lock; retry tokens fence off old failure callbacks. */
    public static function retry(JournalAnalysis $analysis, array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $index => $block) {
            if (self::state($analysis, $block)['status'] === 'failed') {
                $blocks[$index]['readingId'] = self::reserve($analysis, $block['text']);
                $ids[] = $blocks[$index]['readingId'];
            }
        }
        if ($ids !== []) {
            $analysis->result = [...$analysis->result, 'document' => $blocks];
        }

        return array_values(array_filter($ids));
    }

    private static function state(JournalAnalysis $analysis, array $block): array
    {
        $state = $analysis->result['documentReadings'][$block['readingId'] ?? ''] ?? ['status' => 'ready'];
        // A lost worker/message must not leave clients polling forever. Retrying rotates the token.
        if ($state['status'] === 'pending' && ($state['queuedAt'] ?? '') < now()->subMinutes(10)->toISOString()) {
            $state['status'] = 'failed';
        }

        return $state;
    }

    /** Keep history needed by reply/undo snapshots, but discard superseded manual edits. */
    public static function prune(JournalAnalysis $analysis): void
    {
        $ids = array_column($analysis->result['document'], 'readingId');
        foreach (JournalReply::where('analysis_id', $analysis->id)->get(['result']) as $reply) {
            $ids = [...$ids, ...array_column($reply->result['document'] ?? [], 'readingId')];
        }
        $states = array_intersect_key($analysis->result['documentReadings'] ?? [], array_fill_keys(array_filter($ids), true));
        $analysis->result = [...$analysis->result, 'documentReadings' => $states];
    }
}
