<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateDailyAudioPracticeAction
{
    /**
     * Direct callers that omit the callback must arrange for generation to resume.
     *
     * @param  null|callable(string): void  $afterCommit
     */
    public function handle(
        int $userId,
        string $practiceDate,
        int $targetDurationMinutes,
        ?callable $afterCommit = null,
    ): DailyAudioPractice {
        $normalizedPracticeDate = $this->normalizePracticeDate($practiceDate);
        if ($userId < 1) {
            throw new InvalidArgumentException('Daily Audio Practice requires a valid user ID.');
        }
        if (
            $targetDurationMinutes < DailyAudioPracticeGeneration::MIN_TARGET_DURATION_MINUTES
            || $targetDurationMinutes > DailyAudioPracticeGeneration::MAX_TARGET_DURATION_MINUTES
        ) {
            throw new InvalidArgumentException('Daily Audio Practice duration is out of range.');
        }

        return DB::transaction(function () use (
            $afterCommit,
            $normalizedPracticeDate,
            $targetDurationMinutes,
            $userId,
        ): DailyAudioPractice {
            $now = now();
            $practice = DailyAudioPractice::query()
                ->where('user_id', $userId)
                ->whereDate('practice_date', $normalizedPracticeDate)
                ->lockForUpdate()
                ->first();

            if ($practice === null) {
                DB::table('daily_audio_practices')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    // Bind a date object so SQLite and Postgres store the same value as Eloquent casts.
                    'practice_date' => $normalizedPracticeDate,
                    'status' => 'draft',
                    'target_duration_minutes' => $targetDurationMinutes,
                    'target_language' => 'ja',
                    'native_language' => 'en',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // insertOrIgnore lets the unique key settle concurrent same-day creates.
                $practice = DailyAudioPractice::query()
                    ->where('user_id', $userId)
                    ->whereDate('practice_date', $normalizedPracticeDate)
                    ->lockForUpdate()
                    ->sole();
            }

            $this->ensureTracks($practice);

            if ($practice->status !== 'generating') {
                $this->resetTracks($practice);
            }

            $practice->status = 'generating';
            $practice->target_duration_minutes = $targetDurationMinutes;
            $practice->target_language = 'ja';
            $practice->native_language = 'en';
            $practice->error_message = null;
            $practice->save();

            if ($afterCommit !== null) {
                DB::afterCommit(static fn () => $afterCommit($practice->id));
            }

            return $practice->load([
                'tracks' => fn ($query) => $query->orderBy('sort_order'),
            ]);
        });
    }

    private function normalizePracticeDate(string $practiceDate): CarbonImmutable
    {
        $practiceDate = trim($practiceDate);
        $normalized = CarbonImmutable::createFromFormat('!Y-m-d', $practiceDate, 'UTC');

        if (
            $normalized === false
            || $normalized->format('Y-m-d') !== $practiceDate
        ) {
            throw new InvalidArgumentException(
                'Daily Audio Practice date must use the YYYY-MM-DD format.',
            );
        }

        return $normalized;
    }

    private function ensureTracks(DailyAudioPractice $practice): void
    {
        $now = now();

        foreach (DailyAudioPracticeGeneration::TRACKS as $track) {
            DB::table('daily_audio_practice_tracks')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'practice_id' => $practice->id,
                'mode' => $track['mode'],
                'status' => 'draft',
                'title' => $track['title'],
                'sort_order' => $track['sortOrder'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DailyAudioPracticeTrack::query()
                ->where('practice_id', $practice->id)
                ->where('mode', $track['mode'])
                ->update([
                    'title' => $track['title'],
                    'sort_order' => $track['sortOrder'],
                ]);
        }
    }

    private function resetTracks(DailyAudioPractice $practice): void
    {
        DailyAudioPracticeTrack::query()
            ->where('practice_id', $practice->id)
            ->where('mode', 'drill')
            ->update([
                'status' => 'draft',
                'script_units_json' => null,
                'audio_url' => null,
                'timing_data' => null,
                'approx_duration_seconds' => null,
                'generation_metadata_json' => null,
                'error_message' => null,
            ]);

        DailyAudioPracticeTrack::query()
            ->where('practice_id', $practice->id)
            ->whereIn('mode', ['dialogue', 'story'])
            ->update([
                'status' => 'skipped',
                'script_units_json' => null,
                'audio_url' => null,
                'timing_data' => null,
                'approx_duration_seconds' => null,
                'generation_metadata_json' => DailyAudioPracticeGeneration::SKIPPED_TRACK_METADATA,
                'error_message' => null,
            ]);
    }
}
