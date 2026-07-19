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
        $apiKey = trim((string) config('services.openai.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is required for study card generation.');
        }

        try {
            $response = Http::baseUrl((string) config('services.openai.base_url'))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('/responses', [
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
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenAI failed to generate study content.', 0, $exception);
        }

        if (! $response->successful()) {
            throw $this->serviceException($response);
        }

        $outputText = $response->json('output_text');
        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        $output = $response->json('output');
        if (is_array($output)) {
            foreach ($output as $item) {
                if (! is_array($item) || ! is_array($item['content'] ?? null)) {
                    continue;
                }

                foreach ($item['content'] as $content) {
                    $text = is_array($content) ? ($content['text'] ?? null) : null;
                    if (is_string($text) && trim($text) !== '') {
                        return $text;
                    }
                }
            }
        }

        throw new RuntimeException('OpenAI returned no text for the study vocab bundle.');
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
