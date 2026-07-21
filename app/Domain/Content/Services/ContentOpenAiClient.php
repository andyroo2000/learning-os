<?php

namespace App\Domain\Content\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContentOpenAiClient
{
    public const TIMEOUT_SECONDS = 90;

    public function generateText(string $systemInstruction, string $prompt, string $contentLabel = 'Course'): string
    {
        return $this->generate($systemInstruction, $prompt, 'text', $contentLabel);
    }

    public function generateJson(string $systemInstruction, string $prompt, string $contentLabel = 'Course'): string
    {
        return $this->generate($systemInstruction, $prompt, 'json_object', $contentLabel);
    }

    private function generate(string $systemInstruction, string $prompt, string $format, string $contentLabel): string
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException("OPENAI_API_KEY is required for {$contentLabel} generation.");
        }

        try {
            $response = Http::baseUrl((string) config('services.openai.base_url'))
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('/responses', [
                    'model' => (string) config('services.openai.content_model'),
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
                        'effort' => (string) config('services.openai.content_reasoning_effort'),
                    ],
                    'text' => ['format' => ['type' => $format]],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException("OpenAI failed to generate {$contentLabel} content.", 0, $exception);
        }

        if (! $response->successful()) {
            throw $this->serviceException($response, $contentLabel);
        }

        $text = $response->json('output_text');
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $output = $response->json('output');
        if (is_array($output)) {
            foreach ($output as $item) {
                $contentItems = is_array($item) && is_array($item['content'] ?? null)
                    ? $item['content']
                    : [];

                foreach ($contentItems as $content) {
                    $text = is_array($content) ? ($content['text'] ?? null) : null;
                    if (is_string($text) && trim($text) !== '') {
                        return trim($text);
                    }
                }
            }
        }

        throw new RuntimeException("OpenAI returned no {$contentLabel} content.");
    }

    private function serviceException(Response $response, string $contentLabel): RuntimeException
    {
        $message = strtolower((string) $response->json('error.message'));

        if (in_array($response->status(), [401, 403], true) || str_contains($message, 'api key')) {
            return new RuntimeException('AI generation provider rejected the configured credentials.');
        }
        if ($response->status() === 429) {
            return new RuntimeException("OpenAI is rate limiting {$contentLabel} generation.");
        }

        return new RuntimeException("OpenAI failed to generate {$contentLabel} content.");
    }
}
