<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiStudyImageGenerator
{
    public const TIMEOUT_SECONDS = 60;

    public const PROMPT_GUARDRAIL = "Use a construction paper children's book illustration style. If people are shown, they should be Japanese. If a place is shown, set it in Japan. Scene only. No text, words, letters, captions, labels, signs, logos, watermarks, UI, flashcard layout, worksheet, poster, or title.";

    public function generate(string $imagePrompt): string
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw StudyPreviewMediaGenerationException::providerUnavailable('OpenAI');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('/images/generations', [
                    'model' => (string) config('services.openai.study_image_model'),
                    'prompt' => $this->guardedPrompt($imagePrompt),
                    'n' => 1,
                    'size' => '1024x1024',
                    'quality' => 'medium',
                    'output_format' => 'webp',
                ]);
        } catch (ConnectionException $exception) {
            throw StudyPreviewMediaGenerationException::providerFailed('OpenAI', $exception);
        }

        if (! $response->successful()) {
            throw $this->serviceException($response);
        }

        $encoded = $response->json('data.0.b64_json');
        if (! is_string($encoded) || $encoded === '') {
            throw StudyPreviewMediaGenerationException::invalidProviderOutput('OpenAI');
        }

        $bytes = base64_decode($encoded, true);
        if (! is_string($bytes) || ! $this->isWebp($bytes)) {
            throw StudyPreviewMediaGenerationException::invalidProviderOutput('OpenAI');
        }

        return $bytes;
    }

    public function guardedPrompt(string $imagePrompt): string
    {
        $imagePrompt = trim($imagePrompt);

        if ($imagePrompt === '') {
            return self::PROMPT_GUARDRAIL;
        }

        if (str_contains($imagePrompt, self::PROMPT_GUARDRAIL)) {
            return $imagePrompt;
        }

        return "{$imagePrompt}\n\n".self::PROMPT_GUARDRAIL;
    }

    private function isWebp(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP';
    }

    private function serviceException(Response $response): StudyPreviewMediaGenerationException
    {
        $message = strtolower((string) $response->json('error.message'));

        if (in_array($response->status(), [401, 403], true) || str_contains($message, 'api key')) {
            return StudyPreviewMediaGenerationException::providerUnavailable('OpenAI');
        }

        if ($response->status() === 429) {
            return StudyPreviewMediaGenerationException::providerRateLimited('OpenAI');
        }

        return StudyPreviewMediaGenerationException::providerFailed('OpenAI');
    }
}
