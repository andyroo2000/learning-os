<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FishAudioSpeechGenerator
{
    public const MAX_TEXT_LENGTH = 15_000;

    public const TIMEOUT_SECONDS = 90;

    public function generate(string $text, string $voiceId): string
    {
        $apiKey = trim((string) config('services.fish_audio.api_key'));
        if ($apiKey === '') {
            throw StudyPreviewMediaGenerationException::providerUnavailable('Fish Audio');
        }

        $text = trim($text);
        if ($text === '' || mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw StudyPreviewMediaGenerationException::invalidProviderOutput('Fish Audio');
        }

        $referenceId = preg_replace('/^fishaudio:/i', '', trim($voiceId));
        if (! is_string($referenceId) || preg_match('/^[a-f0-9]{32}$/i', $referenceId) !== 1) {
            throw StudyPreviewMediaGenerationException::providerUnavailable('Fish Audio');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.fish_audio.base_url'), '/'))
                ->accept('audio/mpeg')
                ->asJson()
                ->withToken($apiKey)
                ->withHeaders(['model' => (string) config('services.fish_audio.backend')])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('/v1/tts', [
                    'text' => $text,
                    'reference_id' => $referenceId,
                    'format' => 'mp3',
                    'mp3_bitrate' => 128,
                    'sample_rate' => 44_100,
                    'prosody' => [
                        'speed' => 1,
                        'volume' => 0,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw StudyPreviewMediaGenerationException::providerFailed('Fish Audio', $exception);
        }

        if (! $response->successful()) {
            throw $this->serviceException($response);
        }

        $bytes = $response->body();
        if (! $this->isMp3($bytes)) {
            throw StudyPreviewMediaGenerationException::invalidProviderOutput('Fish Audio');
        }

        return $bytes;
    }

    private function isMp3(string $bytes): bool
    {
        if (strlen($bytes) < 3) {
            return false;
        }

        if (substr($bytes, 0, 3) === 'ID3') {
            return true;
        }

        return strlen($bytes) >= 2
            && ord($bytes[0]) === 0xFF
            && (ord($bytes[1]) & 0xE0) === 0xE0;
    }

    private function serviceException(Response $response): StudyPreviewMediaGenerationException
    {
        if (in_array($response->status(), [401, 402, 403], true)) {
            return StudyPreviewMediaGenerationException::providerUnavailable('Fish Audio');
        }

        if ($response->status() === 429) {
            return StudyPreviewMediaGenerationException::providerRateLimited('Fish Audio');
        }

        return StudyPreviewMediaGenerationException::providerFailed('Fish Audio');
    }
}
