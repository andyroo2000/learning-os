<?php

namespace App\Support\Audio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomaticSpeechDirections
{
    private const MAX_CLASSIFICATION_LENGTH = 2000;

    public function __construct(private readonly OpenAiSpeechDeliveryClassifier $classifier) {}

    public function apply(string $text): string
    {
        if (! $this->isEligible($text)) {
            return $text;
        }

        try {
            $model = (string) config('services.openai.study_card_model');
            $key = 'speech-delivery:v1:'.hash('sha256', $model."\0".$text);
            $style = Cache::remember($key, now()->addDay(),
                fn (): string => $this->classifier->classify($text, $model)->value);
            $direction = SpeechDeliveryStyle::tryFrom((string) $style)?->direction() ?? '';

            return $direction === '' ? $text : '['.$direction.'] '.$text;
        } catch (Throwable $exception) {
            // Optional direction must never prevent otherwise valid speech generation.
            Log::warning('Automatic speaking directions unavailable; using plain speech.', [
                'exception_type' => $exception::class,
            ]);

            return $text;
        }
    }

    private function isEligible(string $text): bool
    {
        if (! config('services.fish_audio.automatic_directions', true)) {
            return false;
        }
        if (config('services.fish_audio.backend') !== 'drama-3') {
            return false;
        }
        if (trim((string) config('services.openai.api_key')) === '') {
            return false;
        }

        // Preserve authored performance/phoneme tags and avoid assigning one mood to a long script.
        return mb_strlen($text, 'UTF-8') <= self::MAX_CLASSIFICATION_LENGTH
            && preg_match('/[\[\]<>]/u', $text) === 0;
    }
}
