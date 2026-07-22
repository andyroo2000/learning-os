<?php

namespace App\Domain\Admin\Support;

final class AdminCourseDialoguePrompt
{
    /**
     * @param  list<array{word: string, reading?: string, translation: string}>  $vocabulary
     * @param  list<array{pattern: string, meaning: string, example: string, exampleTranslation: string}>  $grammar
     * @return array{prompt: string, metadata: array{targetExchangeCount: int, vocabularySeeds: string, grammarSeeds: string}}
     */
    public static function build(
        string $sourceText,
        string $episodeTitle,
        string $targetLanguage,
        int $targetDurationMinutes,
        ?string $jlptLevel,
        string $speaker1Gender,
        string $speaker2Gender,
        array $vocabulary = [],
        array $grammar = [],
    ): array {
        $targetExchangeCount = max(6, (int) floor(($targetDurationMinutes * 60) / 90));
        $language = mb_strtoupper($targetLanguage);
        $vocabularySeed = self::vocabularySeed($jlptLevel, $vocabulary);
        $grammarSeed = self::grammarSeed($jlptLevel, $grammar);
        $jlptConstraint = $jlptLevel === null || $jlptLevel === ''
            ? ''
            : "\n\nIMPORTANT JLPT LEVEL CONSTRAINT:\n"
                ."- Target level: {$jlptLevel} (".self::jlptDescription($jlptLevel).")\n"
                ."- Use vocabulary and grammar structures appropriate for students at this level\n"
                ."- Avoid using words or structures significantly above this level\n"
                .'- Focus on practical, conversational language at this proficiency level'
                .$vocabularySeed.$grammarSeed;
        $speakerConstraint = "\n\nSPEAKERS:\n"
            ."- Use exactly TWO speakers throughout the dialogue\n"
            ."- Speaker 1 is {$speaker1Gender}; choose a name that matches this gender\n"
            ."- Speaker 2 is {$speaker2Gender}; choose a name that matches this gender\n"
            .'- Start the conversation with Speaker 1 and alternate turns';
        $readingStep = $targetLanguage === 'ja'
            ? 'Provide a SEPARATE reading in BRACKET NOTATION - put hiragana in brackets after each kanji (this goes in "reading"). Example textL2: "北海道に行きました", reading: "北[ほっ]海[かい]道[どう]に行[い]きました"'
            : '';
        $japaneseInstructions = $targetLanguage === 'ja' ? self::japaneseInstructions() : '';
        $readingExample = $targetLanguage === 'ja'
            ? "\n      \"reading\": \"北[ほっ]海[かい]道[どう]に行[い]きました\"," : '';
        $vocabularyExample = $targetLanguage === 'ja'
            ? '"reading": "...", "jlptLevel": "N4",' : '';
        $exampleText = $targetLanguage === 'ja' ? '北海道に行きました' : '...';

        $prompt = <<<PROMPT
You are creating a Pimsleur-style language lesson based on this scenario:

Title: "{$episodeTitle}"
Scenario: "{$sourceText}"

Generate {$targetExchangeCount} dialogue exchanges in {$language} for this scenario. Create a realistic back-and-forth conversation.

For each exchange:
1. Write the line in {$language} as plain text (this goes in "textL2")
2. {$readingStep}
3. Provide an English translation
4. Identify the speaker (give them a name like "Kenji", "Maria", "Bartender", etc.)
5. Provide a relationship description for narration (e.g., "Your friend", "The bartender", "Your colleague")
6. Extract 2-4 key vocabulary words or short phrases that would be useful to teach

{$jlptConstraint}

Guidelines:
- Make the conversation natural and realistic
- Vary between questions and statements
- Keep the two speaker names consistent across all exchanges
- IMPORTANT: Keep each turn SHORT - one simple sentence, or at most one sentence + a tiny follow-up interjection/question
- AVOID run-on sentences or multiple topics in one turn
- Each turn should focus on ONE idea that's easy to hold in working memory
- Include practical, useful phrases
- For vocabulary, extract words/phrases in the EXACT form used in the sentence (e.g., past tense "楽しかった" not dictionary form "楽しい")
- Extract meaningful chunks, not just single words (e.g., "was fun" not just "fun", "I rode" not just "rode")
- The translation should match the exact form extracted (e.g., "was fun" for "楽しかった", not "fun")

Examples of GOOD turn length:
- "How was your trip to Hokkaido?" (simple question)
- "It was amazing! I went cycling." (statement + brief detail)
- "Really? How long were you there?" (interjection + short question)

Examples of BAD turn length (TOO LONG):
- "I went to Hokkaido last month and stayed for two weeks cycling around the island, and the weather was perfect except for one rainy day." (too many ideas)
- "That sounds wonderful! I've always wanted to visit Hokkaido. Did you enjoy the food there and what was your favorite place?" (multiple topics)

{$speakerConstraint}

{$japaneseInstructions}

Return ONLY a JSON object (no markdown, no explanation):
{
  "exchanges": [
    {
      "order": 0,
      "speakerName": "Kenji",
      "relationshipName": "Your friend",
      "textL2": "{$exampleText}",{$readingExample}
      "translation": "...",
      "vocabulary": [
        {"word": "...", {$vocabularyExample} "translation": "..."},
        {"word": "...", {$vocabularyExample} "translation": "..."}
      ]
    }
  ]
}
PROMPT;

        return [
            'prompt' => $prompt,
            'metadata' => [
                'targetExchangeCount' => $targetExchangeCount,
                'vocabularySeeds' => $vocabularySeed,
                'grammarSeeds' => $grammarSeed,
            ],
        ];
    }

    /** @param list<array{word: string, reading?: string, translation: string}> $words */
    private static function vocabularySeed(?string $level, array $words): string
    {
        if ($level === null || $level === '' || $words === []) {
            return '';
        }

        $formatted = implode(', ', array_map(
            static fn (array $word): string => sprintf(
                '%s (%s) - %s',
                $word['word'],
                $word['reading'] ?? '',
                $word['translation'],
            ),
            $words,
        ));

        return "\n\nSUGGESTED {$level} VOCABULARY TO INCORPORATE:\n"
            ."Try to naturally use some of these JLPT {$level}-level words in the dialogue:\n"
            ."{$formatted}\n\n"
            ."You don't need to use all of them - just incorporate 5-10 naturally where they fit the conversation context.";
    }

    /** @param list<array{pattern: string, meaning: string, example: string, exampleTranslation: string}> $points */
    private static function grammarSeed(?string $level, array $points): string
    {
        if ($level === null || $level === '' || $points === []) {
            return '';
        }

        $formatted = implode("\n", array_map(
            static fn (array $point): string => sprintf(
                '- %s (%s): %s (%s)',
                $point['pattern'],
                $point['meaning'],
                $point['example'],
                $point['exampleTranslation'],
            ),
            $points,
        ));

        return "\n\nSUGGESTED {$level} GRAMMAR PATTERNS TO INCORPORATE:\n"
            ."Try to naturally use 2-3 of these JLPT {$level}-level grammar patterns in the dialogue:\n"
            ."{$formatted}\n\nUse these patterns where they naturally fit the conversation flow.";
    }

    private static function japaneseInstructions(): string
    {
        return <<<'TEXT'
IMPORTANT for Japanese:
SENTENCE READING FORMAT:
- Use BRACKET NOTATION: put hiragana in brackets after each kanji
- Example: "北[ほっ]海[かい]道[どう]に行[い]きました"
- For particles and kana-only words, write them normally without brackets: "に", "を", "は"

VOCABULARY WORDS:
- "word" should contain ONLY Japanese characters (kanji/kana), NO romanization
- "reading" should contain the hiragana reading (e.g., "ほっかいどう" for 北海道)
- "jlptLevel" should indicate the JLPT level where this word is typically taught (N5, N4, N3, N2, N1)
  - N5 = beginner (basic words like これ, ありがとう, 行く)
  - N4 = upper beginner
  - N3 = intermediate
  - N2 = upper intermediate
  - N1 = advanced
- Do NOT include romanization in parentheses
- Example: {"word": "北海道", "reading": "ほっかいどう", "translation": "Hokkaido", "jlptLevel": "N4"}
TEXT;
    }

    private static function jlptDescription(string $level): string
    {
        return [
            'N5' => 'Beginner - Basic grammar, ~700 vocabulary words',
            'N4' => 'Upper Beginner - Elementary grammar, ~1500 vocabulary words',
            'N3' => 'Intermediate - Everyday grammar, ~3750 vocabulary words',
            'N2' => 'Upper Intermediate - Advanced grammar, ~6000 vocabulary words',
            'N1' => 'Advanced - Complex grammar, ~10000 vocabulary words',
        ][$level] ?? $level;
    }
}
