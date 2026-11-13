<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalQuestion;
use App\Jobs\RenderJournalQuestionAudio;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class JournalQuestionAudioState
{
    /** Called under the journal owner lock. Queue publication happens only after the snapshot commits. */
    public static function schedule(JournalQuestion $question): void
    {
        if ($question->tutor === null || self::alreadyPrepared($question)) {
            return;
        }
        $owner = $question->analysis->entry->user_id;
        if (! RateLimiter::attempt('journal-question-audio:'.$owner, 5, fn () => true, 60)) {
            $question->audio_status = 'failed';
            $question->audio_error = 'Please wait a minute, then retry audio.';
            $question->save();

            return;
        }
        $token = strtolower((string) Str::ulid());
        $question->audio_status = 'queued';
        $question->audio_attempt_id = $token;
        $question->audio_requested_at = now();
        $question->audio_error = null;
        $question->save();
        DB::afterCommit(function () use ($question, $token): void {
            try {
                Bus::dispatch(new RenderJournalQuestionAudio($question->id, $token));
            } catch (Throwable $error) {
                // Feedback remains ready even when the queue is temporarily unavailable.
                self::fail($question->id, $token);
                report($error);
            }
        });
    }

    private static function alreadyPrepared(JournalQuestion $question): bool
    {
        if ($question->audio_status === 'ready') {
            return Storage::disk('local')->exists($question->audio_path);
        }

        return in_array($question->audio_status, ['queued', 'processing'], true)
            && $question->audio_requested_at?->gt(now()->subSeconds(180));
    }

    public static function fail(string $id, string $token): void
    {
        JournalQuestion::whereKey($id)->where('audio_attempt_id', $token)->whereIn('audio_status', ['queued', 'processing'])
            ->update(['audio_status' => 'failed', 'audio_error' => 'Audio could not be prepared. Please retry.']);
    }
}
