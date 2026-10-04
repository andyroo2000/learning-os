<?php

namespace App\Domain\Readings\Actions;

use App\Domain\Readings\Models\Reading;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

final class GenerateReadingAudio
{
    public const VOICE_ID = 'fishaudio:875668667eb94c20b09856b971d9ca2f';

    public function __construct(private readonly FishAudioSpeechGenerator $speech) {}

    public function handle(Reading $reading, int $sentence): string
    {
        $entry = $reading->document['sentences'][$sentence] ?? [];
        $text = $entry['speechText'] ?? $entry['text'] ?? null;
        abort_unless(is_string($text), 404);
        $hash = hash('sha256', self::VOICE_ID.'|'.$text);
        $path = 'readings/'.$reading->id.'/audio/'.$hash.'.mp3';

        return Cache::lock('reading-audio:'.$reading->id.':'.$hash, 120)
            ->block(5, function () use ($reading, $text, $path): string {
                if (! Storage::disk('local')->exists($path)) {
                    $this->generate($reading, $text, $path);
                }

                return $path;
            });
    }

    private function generate(Reading $reading, string $text, string $path): void
    {
        $allowed = RateLimiter::attempt('reading-generation:'.$reading->user_id, 10, fn () => true, 60);
        abort_unless($allowed, 429, 'Please wait a minute before generating more sentences.');
        $bytes = $this->speech->generate($text, self::VOICE_ID);
        abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Could not save sentence audio.');
    }
}
