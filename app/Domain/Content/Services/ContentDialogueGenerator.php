<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Results\ContentDialogueGenerationResult;
use InvalidArgumentException;

final readonly class ContentDialogueGenerator
{
    public function __construct(private ContentOpenAiClient $client) {}

    /** @param array{sourceText: string, targetLanguage: string, nativeLanguage: string, jlptLevel: string|null} $episode */
    public function generate(array $episode, GenerateContentDialogueData $input): ContentDialogueGenerationResult
    {
        if (mb_strlen($episode['sourceText']) > 50_000) {
            throw new InvalidArgumentException('Episode source is too large for dialogue generation.');
        }

        $response = $this->client->generateJson(
            $this->systemInstruction($episode['targetLanguage'], $input),
            $this->prompt($episode, $input),
            'Dialogue',
        );

        return ContentDialogueGenerationResult::fromJson(
            $response,
            $input,
            $episode['targetLanguage'],
        );
    }

    private function systemInstruction(string $targetLanguage, GenerateContentDialogueData $input): string
    {
        $language = $targetLanguage === 'ja' ? 'Japanese' : $targetLanguage;
        $speakers = array_map(
            static fn (array $speaker): string => sprintf(
                '%s (%s, %s)',
                GenerateContentDialogueData::promptName($speaker['name']),
                $speaker['proficiency'],
                $speaker['tone'],
            ),
            $input->speakers,
        );

        return "You generate bounded, natural {$language} dialogues for language learners. "
            .'Match each speaker proficiency and tone, use culturally appropriate language, provide English translations, '
            .'and return only the requested JSON object. Speakers: '.implode(', ', $speakers).'.';
    }

    /** @param array{sourceText: string, targetLanguage: string, nativeLanguage: string, jlptLevel: string|null} $episode */
    private function prompt(array $episode, GenerateContentDialogueData $input): string
    {
        $names = array_map(
            static fn (array $speaker): string => GenerateContentDialogueData::promptName($speaker['name']),
            $input->speakers,
        );
        $jlptLevel = $input->jlptLevel ?? $episode['jlptLevel'];
        $level = $jlptLevel === null ? '' : "\nTarget JLPT level: {$jlptLevel}. Stay at or below this level.";
        $vocabulary = $this->seedSection('Vocabulary seeds', $input->vocabSeedOverride);
        $grammar = $this->seedSection('Grammar seeds', $input->grammarSeedOverride);

        return <<<PROMPT
Create a dialogue based on this story:
"{$episode['sourceText']}"

Use exactly these speakers in this order: {$names[0]}, {$names[1]}.
Generate exactly {$input->dialogueLength} lines, starting with {$names[0]} and strictly alternating speakers.
For every line provide exactly {$input->variationCount} genuinely different variations.{$level}{$vocabulary}{$grammar}

Return exactly this JSON shape with no additional keys:
{
  "title": "A short 2-4 word English topic title",
  "sentences": [
    {
      "speaker": "{$names[0]}",
      "text": "Target-language text",
      "reading": "Japanese bracket furigana, or omit only for non-Japanese",
      "translation": "English translation",
      "variations": ["Variation 1"]
    }
  ]
}
PROMPT;
    }

    private function seedSection(string $label, ?string $value): string
    {
        return $value === null ? '' : "\n{$label}:\n{$value}";
    }
}
