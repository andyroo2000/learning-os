<?php

namespace App\Support\Audio;

use App\Support\Usage\ProviderUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

final class FishAudioSpeechGenerator implements AudioSpeechGenerator
{
    public const MAX_TEXT_LENGTH = 15_000;

    public const TIMEOUT_SECONDS = 90;

    public function __construct(private readonly AutomaticSpeechDirections $directions) {}

    private function client(PendingRequest $request, bool $timed): PendingRequest
    {
        return $request->baseUrl(rtrim((string) config('services.fish_audio.base_url'), '/'))
            ->accept($timed ? 'text/event-stream' : 'audio/mpeg')->asJson()->withToken($this->apiKey())
            ->withHeaders(['model' => (string) config('services.fish_audio.backend')])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function request(FishAudioLine $line, bool $timed): Response
    {
        $client = $this->client(Http::createPendingRequest(), $timed);
        $payload = $line->payload($this->directions, $timed);
        try {
            $response = ProviderUsage::request('fish', (string) config('services.fish_audio.backend'),
                ['input_bytes' => strlen($payload['text'])],
                fn () => $client->post($timed ? '/v1/tts/stream/with-timestamp' : '/v1/tts', $payload));
        } catch (ConnectionException $exception) {
            throw AudioSpeechGenerationException::failed('Fish Audio', $exception);
        }

        return $this->successful($response);
    }

    private function successful(Response $response): Response
    {
        if (! $response->successful()) {
            throw $this->serviceException($response);
        }

        return $response;
    }

    /** @param array<string, array{string, string, float}> $lines */
    public function generateBatch(array $lines): array
    {
        // Metered callers keep their per-request usage records.
        if (Context::getHidden('ai_usage') !== null) {
            return array_map(fn (array $line) => $this->generate(...$line), $lines);
        }

        $responses = Http::pool(function (Pool $pool) use ($lines): void {
            foreach ($lines as $key => [$text, $voice, $speed]) {
                $this->client($pool->as($key), false)->post('/v1/tts', (new FishAudioLine($text, $voice, $speed))->payload($this->directions, false));
            }
        }, concurrency: 2);

        $audio = [];
        foreach ($lines as $key => $line) {
            $response = $responses[$key];
            if ($response instanceof \Throwable) {
                throw AudioSpeechGenerationException::failed('Fish Audio', $response);
            }
            $audio[$key] = $response->status() === 429
                ? $this->retryThrottledLine($response, $line)
                : $this->mp3($this->successful($response)->body());
        }

        return $audio;
    }

    private function retryThrottledLine(Response $response, array $line): string
    {
        $retryAfter = $response->header('Retry-After');
        $delay = is_numeric($retryAfter) ? (int) ceil((float) $retryAfter) : (int) strtotime($retryAfter) - time();
        if ($delay > 30) {
            throw $this->serviceException($response);
        }
        Sleep::sleep(max(1, $delay));

        return $this->generate(...$line);
    }

    public function generate(string $text, string $voiceId, float $speed = 1.0): string
    {
        return $this->mp3($this->request(new FishAudioLine($text, $voiceId, $speed), false)->body());
    }

    private function mp3(string $bytes): string
    {
        if (! $this->isMp3($bytes)) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }

        return $bytes;
    }

    public function generateTimed(string $text, string $voiceId): array
    {
        $result = FishAudioTimestampStream::decode($this->request(new FishAudioLine($text, $voiceId), true)->body());
        if (! $this->isMp3($result['audio'])) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }

        return $result;
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('services.fish_audio.api_key'));
        if ($apiKey === '') {
            throw AudioSpeechGenerationException::unavailable('Fish Audio');
        }

        return $apiKey;
    }

    private function isMp3(string $bytes): bool
    {
        if (str_starts_with($bytes, 'ID3')) {
            return true;
        }
        if (strlen($bytes) < 2) {
            return false;
        }

        return ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0;
    }

    private function serviceException(Response $response): AudioSpeechGenerationException
    {
        if (in_array($response->status(), [401, 402, 403], true)) {
            return AudioSpeechGenerationException::unavailable('Fish Audio');
        }
        if ($response->status() === 429) {
            return AudioSpeechGenerationException::rateLimited('Fish Audio');
        }

        return AudioSpeechGenerationException::failed('Fish Audio');
    }
}
