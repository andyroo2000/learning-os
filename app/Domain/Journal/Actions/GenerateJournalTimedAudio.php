<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Support\JournalAudioState;
use App\Domain\Journal\Support\JournalAudioVersion;
use App\Domain\Journal\Support\JournalSentenceTimings;
use App\Domain\Journal\Support\JournalTimedAudioCache;
use App\Domain\Journal\Support\JournalWriteTransaction;
use App\Support\Audio\FishAudioSpeechGenerator;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GenerateJournalTimedAudio
{
    public function __construct(private readonly FishAudioSpeechGenerator $speech) {}

    public function entry(JournalEntry $entry, array $input): array
    {
        return $this->handle($entry, fn () => [
            JournalAudioVersion::text($entry->fresh() ?? abort(404), $input), $input['voiceId'],
        ], true);
    }

    public function question(JournalEntry $entry, string $questionID): array
    {
        return $this->handle($entry, function () use ($entry, $questionID): array {
            $question = JournalQuestion::whereHas('analysis', fn ($query) => $query->where('entry_id', $entry->id))->findOrFail($questionID);
            abort_unless(isset($question->tutor['voiceId']), 409, 'Choose a tutor before playing audio.');

            return [$question->text, $question->tutor['voiceId']];
        }, false);
    }

    private function handle(JournalEntry $entry, Closure $source, bool $remember): array
    {
        [$text, $voice] = $source();
        if (mb_strlen($text) > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
            throw ValidationException::withMessages(['audio' => 'Audio supports entries up to 15,000 characters.']);
        }
        $path = JournalAudioState::path($entry, $voice, $text).'.timed.json';

        return Cache::lock('journal-timed:'.hash('sha256', $path), 180)->block(5, function () use ($entry, $source, $remember, $text, $voice, $path): array {
            $saved = JournalTimedAudioCache::read($path);
            $cached = $saved !== null;
            $data = $saved ?? $this->generate($entry, $text, $voice);

            return JournalWriteTransaction::run($entry->user_id, function () use ($entry, $source, $remember, $text, $voice, $path, $cached, $data): array {
                abort_unless(JournalEntry::whereKey($entry->id)->exists(), 404);
                abort_unless($source() === [$text, $voice], 409, 'The text changed while audio was being prepared.');
                $this->publish($entry, $path, $cached ? null : $data, $remember ? [$voice, $text] : null);

                return $data;
            });
        });
    }

    private function generate(JournalEntry $entry, string $text, string $voice): array
    {
        abort_unless(RateLimiter::attempt('journal-timed:'.$entry->user_id, 5, fn () => true, 60), 429, 'Please wait a minute before generating more audio.');
        $recording = $this->speech->generateTimed($text, $voice);

        return [
            'audioBase64' => base64_encode($recording['audio']),
            'sentences' => JournalSentenceTimings::build($text, $recording['segments']),
        ];
    }

    private function publish(JournalEntry $entry, string $path, ?array $data, ?array $preference): void
    {
        try {
            // Missing alignment is playable, but never pins a transient provider failure in cache.
            if ($data !== null && $data['sentences'] !== []) {
                abort_unless(Storage::disk('local')->put($path, json_encode($data, JSON_THROW_ON_ERROR)), 503, 'Audio could not be saved.');
            }
            if ($preference !== null) {
                JournalAudioState::rememberGeneration($entry, ...$preference);
            }
        } catch (Throwable $error) {
            if ($data !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }
}
