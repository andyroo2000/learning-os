<?php

namespace App\Domain\Content\Services;

use InvalidArgumentException;

final readonly class ContentDialogueImagePlanner
{
    private const STYLE = 'construction paper children\'s book illustration';

    public function __construct(private ContentOpenAiClient $client) {}

    /**
     * @param  list<array{id: string, text: string}>  $sentences
     * @return list<array{prompt: string, order: int, sentenceStartId: string, sentenceEndId: string, url: string}>
     */
    public function plan(string $sourceText, string $targetLanguage, array $sentences, int $imageCount): array
    {
        if (mb_strlen($sourceText) > 50_000) {
            throw new InvalidArgumentException('Episode source is too large for image generation.');
        }
        if ($sentences === []) {
            return [];
        }

        $sentencesPerImage = (int) ceil(count($sentences) / $imageCount);
        $images = [];
        for ($index = 0; $index < $imageCount; $index++) {
            $section = array_slice($sentences, $index * $sentencesPerImage, $sentencesPerImage);
            if ($section === []) {
                break;
            }

            $sectionText = implode(' ', array_column($section, 'text'));
            $images[] = [
                'prompt' => $this->client->generateText(
                    'You create bounded, vivid visual scene prompts for language-learning content. Return only the prompt.',
                    $this->prompt($sourceText, $sectionText, $targetLanguage),
                    'Image prompt',
                ),
                'order' => $index,
                'sentenceStartId' => $section[0]['id'],
                'sentenceEndId' => $section[array_key_last($section)]['id'],
                'url' => 'https://placehold.co/800x600/EEF3FB/5E6AD8?text=Scene+'.($index + 1),
            ];
        }

        return $images;
    }

    private function prompt(string $sourceText, string $sectionText, string $targetLanguage): string
    {
        $style = self::STYLE;

        return <<<PROMPT
Create one detailed visual prompt for a language-learning scene.

Story: {$sourceText}
Dialogue section: {$sectionText}
Target language: {$targetLanguage}

Use a {$style} style. Capture the key action, setting, and atmosphere. If people are shown, they should be Japanese; if a place is shown, set it in Japan. Scene only. No text, words, letters, captions, labels, signs, logos, watermarks, UI, flashcard layout, worksheet, poster, or title.
PROMPT;
    }
}
