<?php

namespace App\Domain\Study\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiStudyCardGenerator
{
    public const TIMEOUT_SECONDS = 90;

    public function generateJson(
        string $systemInstruction,
        string $prompt,
        ?string $model = null,
        ?string $reasoningEffort = null,
    ): string {
        $response = $this->request(
            $this->apiKey(),
            $this->requestPayload($systemInstruction, $prompt, $model, $reasoningEffort),
        );

        if (! $response->successful()) {
            throw $this->serviceException($response);
        }

        $outputText = $this->responseText($response);
        if ($outputText !== null) {
            return $outputText;
        }

        throw new RuntimeException('OpenAI returned no text for the study vocab bundle.');
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is required for study card generation.');
        }

        return $apiKey;
    }

    /** @param array<string, mixed> $payload */
    private function request(string $apiKey, array $payload): Response
    {
        try {
            return Http::baseUrl((string) config('services.openai.base_url'))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('/responses', $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenAI failed to generate study content.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function requestPayload(
        string $systemInstruction,
        string $prompt,
        ?string $model,
        ?string $reasoningEffort,
    ): array {
        return [
            'model' => $model ?? (string) config('services.openai.study_card_model'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [['type' => 'input_text', 'text' => $systemInstruction]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $prompt]],
                ],
            ],
            'reasoning' => [
                'effort' => $reasoningEffort
                    ?? (string) config('services.openai.study_card_reasoning_effort'),
            ],
            'text' => [
                'format' => ['type' => 'json_object'],
            ],
        ];
    }

    private function responseText(Response $response): ?string
    {
        $outputText = $this->nonBlankText($response->json('output_text'));
        if ($outputText !== null) {
            return $outputText;
        }

        $output = $response->json('output');
        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            $text = $this->itemText($item);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function itemText(mixed $item): ?string
    {
        if (! is_array($item) || ! is_array($item['content'] ?? null)) {
            return null;
        }

        foreach ($item['content'] as $content) {
            $text = $this->nonBlankText(is_array($content) ? ($content['text'] ?? null) : null);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function nonBlankText(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    private function serviceException(Response $response): RuntimeException
    {
        $message = strtolower((string) $response->json('error.message'));

        if (in_array($response->status(), [401, 403], true) || str_contains($message, 'api key')) {
            return new RuntimeException('AI generation provider rejected the configured credentials.');
        }

        if ($response->status() === 429) {
            return new RuntimeException('OpenAI is rate limiting study generation.');
        }

        return new RuntimeException('OpenAI failed to generate study content.');
    }
}
