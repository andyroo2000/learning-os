<?php

namespace App\Support\Rehearsal;

use Closure;
use Illuminate\Database\ConnectionInterface;

class ConvoLabDailyAudioImporter
{
    /**
     * @param  Closure(mixed, string): string  $sourceUuid
     * @param  Closure(string): int  $mappedUserId
     */
    public function __construct(
        private readonly Closure $sourceUuid,
        private readonly Closure $mappedUserId,
    ) {}

    /**
     * @return array{practices: int, tracks: int}
     */
    public function import(ConnectionInterface $source, ConnectionInterface $target): array
    {
        [$practiceUserIds, $practiceCount] = $this->importPractices($source, $target);
        $trackCount = $this->importTracks($source, $target, $practiceUserIds);

        return ['practices' => $practiceCount, 'tracks' => $trackCount];
    }

    /**
     * @return array{array<string, int>, int}
     */
    private function importPractices(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $practiceUserIds = [];
        $count = 0;

        $source->table('daily_audio_practices')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(200, function ($practices) use ($target, &$count, &$practiceUserIds): void {
                $rows = [];

                foreach ($practices as $practice) {
                    [$id, $userId, $row] = $this->practiceRow($practice);
                    $practiceUserIds[$id] = $userId;
                    $rows[] = $row;
                }

                if ($rows !== []) {
                    $target->table('daily_audio_practices')->insert($rows);
                }

                $count += count($rows);
            });

        return [$practiceUserIds, $count];
    }

    /**
     * @return array{string, int, array<string, mixed>}
     */
    private function practiceRow(object $practice): array
    {
        $id = ($this->sourceUuid)($practice->id, 'daily audio practice');
        $userId = ($this->mappedUserId)($practice->userId);

        return [$id, $userId, [
            'id' => $id,
            'user_id' => $userId,
            'convolab_user_id' => $practice->userId,
            'practice_date' => $practice->practiceDate,
            'status' => $practice->status,
            'target_duration_minutes' => $practice->targetDurationMinutes,
            'target_language' => $practice->targetLanguage,
            'native_language' => $practice->nativeLanguage,
            // Historical compatibility payload: imported cards retain these UUIDs in convolab_id.
            'source_card_ids_json' => $practice->sourceCardIdsJson,
            'selection_summary_json' => $practice->selectionSummaryJson,
            'error_message' => $practice->errorMessage,
            'created_at' => $practice->createdAt,
            'updated_at' => $practice->updatedAt,
        ]];
    }

    /**
     * @param  array<string, int>  $practiceUserIds
     */
    private function importTracks(
        ConnectionInterface $source,
        ConnectionInterface $target,
        array $practiceUserIds,
    ): int {
        $count = 0;

        $source->table('daily_audio_practice_tracks')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(500, function ($tracks) use ($target, &$count, $practiceUserIds): void {
                $rows = [];

                foreach ($tracks as $track) {
                    $rows[] = $this->trackRow($track, $practiceUserIds);
                }

                if ($rows !== []) {
                    $target->table('daily_audio_practice_tracks')->insert($rows);
                }

                $count += count($rows);
            });

        return $count;
    }

    /**
     * @param  array<string, int>  $practiceUserIds
     * @return array<string, mixed>
     */
    private function trackRow(object $track, array $practiceUserIds): array
    {
        $practiceId = ($this->sourceUuid)($track->practiceId, 'daily audio practice');

        if (! isset($practiceUserIds[$practiceId])) {
            throw new \RuntimeException(
                "Missing imported daily audio practice mapping for track [{$track->id}].",
            );
        }

        return [
            'id' => ($this->sourceUuid)($track->id, 'daily audio practice track'),
            'practice_id' => $practiceId,
            'mode' => $track->mode,
            'status' => $track->status,
            'title' => $track->title,
            'sort_order' => $track->sortOrder,
            'script_units_json' => $track->scriptUnitsJson,
            'audio_url' => $track->audioUrl,
            'timing_data' => $track->timingData,
            'approx_duration_seconds' => $track->approxDurationSeconds,
            'generation_metadata_json' => $track->generationMetadataJson,
            'error_message' => $track->errorMessage,
            'created_at' => $track->createdAt,
            'updated_at' => $track->updatedAt,
        ];
    }
}
