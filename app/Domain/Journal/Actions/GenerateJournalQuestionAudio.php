<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Support\JournalQuestionAudioState;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateJournalQuestionAudio
{
    public function __construct(private readonly FishAudioSpeechGenerator $speech) {}

    public function handle(string $id, string $token): void
    {
        $snapshot = $this->mutate($id, function (JournalQuestion $question) use ($token) {
            if ($question->audio_attempt_id !== $token || $question->audio_status !== 'queued') {
                return null;
            }
            $question->audio_status = 'processing';
            $question->save();

            return $question;
        });
        if ($snapshot === null) {
            return;
        }
        $path = 'journal/'.$snapshot->analysis->entry_id.'/questions/'.$id.'/'.$token.'.mp3';
        try {
            $bytes = $this->speech->generate($snapshot->text, $snapshot->tutor['voiceId']);
            $this->mutate($id, function (JournalQuestion $question) use ($token, $path, $bytes): void {
                if ($question->audio_attempt_id !== $token || $question->audio_status !== 'processing') {
                    return;
                }
                abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Audio could not be saved.');
                $question->audio_path = $path;
                $question->audio_status = 'ready';
                $question->audio_error = null;
                $question->save();
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            JournalQuestionAudioState::fail($id, $token);
            throw $error;
        }
    }

    private function mutate(string $id, callable $change): mixed
    {
        $entry = JournalQuestion::find($id)?->analysis?->entry;
        if ($entry === null) {
            return null;
        }
        try {
            return JournalWriteTransaction::run($entry->user_id, function () use ($entry, $id, $change) {
                if (JournalEntry::whereKey($entry->id)->lockForUpdate()->first() === null) {
                    return null;
                }
                $question = JournalQuestion::lockForUpdate()->find($id);

                return $question === null ? null : $change($question);
            });
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
