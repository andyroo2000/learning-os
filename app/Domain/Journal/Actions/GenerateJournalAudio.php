<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalAudioState;
use App\Domain\Journal\Support\JournalAudioVersion;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateJournalAudio
{
    public function __construct(private readonly FishAudioSpeechGenerator $speech) {}

    public function handle(JournalEntry $entry, array $input): string
    {
        $text = JournalAudioVersion::text($entry, $input);
        if (mb_strlen($text) > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
            throw ValidationException::withMessages(['audio' => 'Audio supports corrected entries up to 15,000 characters.']);
        }
        $voice = $input['voiceId'];
        $hash = hash('sha256', $voice.'|'.$text);
        $path = JournalAudioState::path($entry, $voice, $text);

        return Cache::lock('journal-audio:'.$entry->id.':'.$hash, 120)->block(5, function () use ($entry, $text, $voice, $path, $input): string {
            if (! Storage::disk('local')->exists($path)) {
                $allowed = RateLimiter::attempt('journal-audio:'.$entry->user_id, 5, fn () => true, 60);
                abort_unless($allowed, 429, 'Please wait a minute before generating more audio.');
                $bytes = $this->speech->generate($text, $voice);
                $this->publish($entry, $input, $path, $bytes);
            }

            JournalAudioVersion::text($this->checkCurrentEntry($entry, $path), $input);

            return $path;
        });
    }

    private function publish(JournalEntry $entry, array $input, string $path, string $bytes): void
    {
        // Synthesis stays outside the transaction; publication serializes with account deletion.
        try {
            JournalWriteTransaction::run($entry->user_id, function () use ($entry, $input, $path, $bytes): void {
                $current = $this->checkCurrentEntry($entry, $path);
                $text = JournalAudioVersion::text($current, $input);
                abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Audio could not be saved.');
                JournalAudioState::rememberGeneration($current, $input['voiceId'], $text);
            });
        } catch (Throwable $exception) {
            // A rolled-back preference write must not leave a cache hit that bypasses publication.
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function checkCurrentEntry(JournalEntry $snapshot, string $path): JournalEntry
    {
        $current = $snapshot->fresh();
        if ($current === null || $current->trashed()) {
            Storage::disk('local')->deleteDirectory('journal/'.$snapshot->id);
            abort(404);
        }
        if ($current->body !== $snapshot->body) {
            Storage::disk('local')->delete($path);
            abort(409, 'The entry changed while audio was being prepared.');
        }

        return $current;
    }
}
