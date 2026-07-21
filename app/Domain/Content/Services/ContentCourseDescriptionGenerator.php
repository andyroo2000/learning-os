<?php

namespace App\Domain\Content\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContentCourseDescriptionGenerator
{
    private const TIMEOUT_SECONDS = 60;

    /** @param list<string> $episodeTitles */
    public function generate(
        array $episodeTitles,
        string $targetLanguage,
        string $nativeLanguage,
    ): string {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is required for Course description generation.');
        }

        $titles = implode(', ', $episodeTitles);
        $prompt = <<<PROMPT
Write a brief, engaging 1-2 sentence description for a Pimsleur-style audio language course based on these dialogue episodes: "{$titles}".

The course teaches {$targetLanguage} to {$nativeLanguage} speakers through interactive audio lessons with spaced repetition.

Write only the description, no formatting or quotes.
PROMPT;

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
                            'content' => [[
                                'type' => 'input_text',
                                'text' => 'You are a helpful language-learning content generator.',
                            ]],
                        ],
                        [
                            'role' => 'user',
                            'content' => [['type' => 'input_text', 'text' => $prompt]],
                        ],
                    ],
                    'reasoning' => [
                        'effort' => (string) config('services.openai.content_reasoning_effort'),
                    ],
                    'text' => ['format' => ['type' => 'text']],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('OpenAI failed to generate a Course description.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI failed to generate a Course description.');
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

        throw new RuntimeException('OpenAI returned no Course description.');
    }
}
