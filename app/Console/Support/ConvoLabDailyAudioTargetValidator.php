<?php

namespace App\Console\Support;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

final class ConvoLabDailyAudioTargetValidator
{
    /**
     * @param  array<string, array{id: string, created_at: string, updated_at: string}>  $practices
     * @param  array<string, array{id: string, practice_id: string, created_at: string, updated_at: string}>  $trackTimestamps
     * @param  array<string, array{id: string, practice_id: string, mode: string}>  $tracks
     */
    public function __construct(
        private readonly ConnectionInterface $target,
        private readonly array $practices,
        private readonly array $trackTimestamps,
        private readonly array $tracks,
    ) {}

    /** @param  Closure(Builder): Builder  $prepareQuery */
    public function assertMatches(Closure $prepareQuery): void
    {
        $this->assertTimestampsMatch($prepareQuery);
        $this->assertTracksMatch($prepareQuery);
    }

    /** @param  Closure(Builder): Builder  $prepareQuery */
    private function assertTimestampsMatch(Closure $prepareQuery): void
    {
        $practiceQuery = $prepareQuery(
            $this->target->table('daily_audio_practices')
                ->whereIn('id', array_keys($this->practices)),
        );
        $targetPractices = $practiceQuery->pluck('id')->mapWithKeys(
            fn (mixed $id): array => [strtolower((string) $id) => true],
        );
        $this->assertPracticesExist($targetPractices);

        $trackQuery = $prepareQuery(
            $this->target->table('daily_audio_practice_tracks')
                ->whereIn('id', array_keys($this->trackTimestamps)),
        );
        $targetTracks = $trackQuery->get(['id', 'practice_id'])->keyBy(
            fn (object $track): string => strtolower((string) $track->id),
        );
        $this->assertTimestampTracksMatch($targetTracks);
    }

    /** @param  Collection<string, true>  $targetPractices */
    private function assertPracticesExist(Collection $targetPractices): void
    {
        foreach ($this->practices as $practice) {
            if (! $targetPractices->has($practice['id'])) {
                throw new RuntimeException(
                    "Learning OS has no Daily Audio practice matching Convo Lab practice [{$practice['id']}].",
                );
            }
        }
    }

    /** @param  Collection<string, object>  $targetTracks */
    private function assertTimestampTracksMatch(Collection $targetTracks): void
    {
        foreach ($this->trackTimestamps as $track) {
            $targetTrack = $targetTracks->get($track['id']);

            if ($targetTrack === null) {
                throw new RuntimeException(
                    "Learning OS has no Daily Audio track matching Convo Lab track [{$track['id']}].",
                );
            }

            if (strtolower((string) $targetTrack->practice_id) !== $track['practice_id']) {
                throw new RuntimeException(
                    "Learning OS Daily Audio track [{$track['id']}] belongs to a different practice.",
                );
            }
        }
    }

    /** @param  Closure(Builder): Builder  $prepareQuery */
    private function assertTracksMatch(Closure $prepareQuery): void
    {
        $legacyQuery = $prepareQuery(
            $this->target->table('daily_audio_practice_tracks')
                ->where('status', 'ready')
                ->whereNotNull('audio_url'),
        );
        $this->assertLegacyTracksHaveSources($legacyQuery->get(['id', 'audio_url']));

        if ($this->tracks === []) {
            return;
        }

        $targetQuery = $prepareQuery(
            $this->target->table('daily_audio_practice_tracks')
                ->whereIn('id', array_keys($this->tracks)),
        );
        $targetTracks = $targetQuery
            ->get(['id', 'practice_id', 'mode', 'status'])
            ->keyBy('id');
        $this->assertImportedTracksMatch($targetTracks);
    }

    /** @param  Collection<int, object>  $targetTracks */
    private function assertLegacyTracksHaveSources(Collection $targetTracks): void
    {
        foreach ($targetTracks as $targetTrack) {
            $audioUrl = (string) $targetTrack->audio_url;
            $hasCanonicalUrl = str_starts_with($audioUrl, '/api/daily-audio-practice/');
            $hasSourceMedia = isset($this->tracks[strtolower((string) $targetTrack->id)]);

            if (! $hasCanonicalUrl && ! $hasSourceMedia) {
                throw new RuntimeException(
                    "Learning OS legacy Daily Audio track [{$targetTrack->id}] ".
                    'has no matching Convo Lab source media.',
                );
            }
        }
    }

    /** @param  Collection<string, object>  $targetTracks */
    private function assertImportedTracksMatch(Collection $targetTracks): void
    {
        foreach ($this->tracks as $track) {
            $targetTrack = $targetTracks->get($track['id']);

            if ($targetTrack === null) {
                throw new RuntimeException(
                    "Learning OS has no Daily Audio track matching Convo Lab track [{$track['id']}].",
                );
            }

            $actual = [
                strtolower((string) $targetTrack->practice_id),
                (string) $targetTrack->mode,
                (string) $targetTrack->status,
            ];
            $expected = [$track['practice_id'], $track['mode'], 'ready'];

            if ($actual !== $expected) {
                throw new RuntimeException(
                    "Learning OS Daily Audio track [{$track['id']}] does not match its ready Convo Lab source.",
                );
            }
        }
    }
}
