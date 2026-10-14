<?php

namespace App\Support\Audio;

use Illuminate\Http\Client\RequestException;
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

        $failureKey = $this->failureKey();
        try {
            if (Cache::has($failureKey)) {
                return $text;
            }
            $model = (string) config('services.openai.study_card_model');
            $key = 'speech-delivery:v1:'.hash('sha256', $this->classifier->promptFingerprint()."\0".$model."\0".$text);
            $style = Cache::remember($key, now()->addDay(),
                fn (): string => $this->classifier->classify($text, $model)->value);
            $direction = SpeechDeliveryStyle::tryFrom((string) $style)?->direction() ?? '';

            return $direction === '' ? $text : '['.$direction.'] '.$text;
        } catch (Throwable $exception) {
            $this->recordFailure($failureKey, $exception);

            return $text;
        }
    }

    private function failureKey(): string
    {
        return 'speech-delivery:unavailable:'.hash('sha256',
            config('services.openai.base_url')."\0".config('services.openai.study_card_model'));
    }

    private function recordFailure(string $key, Throwable $exception): void
    {
        // Avoid repeated analysis timeouts across a batch during a provider outage.
        try {
            Cache::put($key, true, now()->addMinute());
        } catch (Throwable) {
            // A cache outage must not prevent synthesis either.
        }
        Log::warning('Automatic speaking directions unavailable; using plain speech.', [
            'exception_type' => $exception::class,
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
        ]);
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
