<?php

namespace App\Domain\Content\Services;

class ContentCourseDescriptionGenerator
{
    public function __construct(
        private readonly ContentOpenAiClient $client,
    ) {}

    /** @param list<string> $episodeTitles */
    public function generate(
        array $episodeTitles,
        string $targetLanguage,
        string $nativeLanguage,
    ): string {
        $titles = implode(', ', $episodeTitles);
        $prompt = <<<PROMPT
Write a brief, engaging 1-2 sentence description for a Pimsleur-style audio language course based on these dialogue episodes: "{$titles}".

The course teaches {$targetLanguage} to {$nativeLanguage} speakers through interactive audio lessons with spaced repetition.

Write only the description, no formatting or quotes.
PROMPT;

        return $this->client->generateText(
            'You are a helpful language-learning content generator.',
            $prompt,
        );
    }
}
