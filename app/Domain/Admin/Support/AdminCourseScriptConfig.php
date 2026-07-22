<?php

namespace App\Domain\Admin\Support;

final class AdminCourseScriptConfig
{
    /** @return array<string, float|string> */
    public static function forCourse(
        string $targetLanguage,
        string $nativeLanguage,
        string $episodeTitle,
        ?string $jlptLevel,
    ): array {
        $jlptInfo = LegacyJavaScriptValue::isTruthy($jlptLevel)
            ? " This lesson is designed for students at JLPT {$jlptLevel} level."
            : '';

        return [
            'reviewAnticipationSeconds' => 5.0,
            'reviewRepeatPauseSeconds' => 2.5,
            'reviewSlowSpeed' => 0.85,
            'pauseAfterScenarioIntro' => 1.5,
            'pauseAfterSpeakerIntro' => 0.5,
            'pauseAfterL2Playback' => 1.0,
            'pauseAfterTranslation' => 1.0,
            'pauseAfterVocabItem' => 1.5,
            'pauseAfterFullPhrase' => 1.5,
            'pauseForLearnerResponse' => 2.5,
            'pauseBetweenRepetitions' => 1.0,
            'scenarioIntroPrompt' => strtr(self::scenarioIntroPrompt(), [
                '{TARGET_LANGUAGE}' => strtoupper($targetLanguage),
                '{NATIVE_LANGUAGE}' => strtoupper($nativeLanguage),
                '{JLPT_INFO}' => $jlptInfo,
                '{EPISODE_TITLE}' => $episodeTitle,
            ]),
            'progressivePhrasePrompt' => str_replace(
                '{TARGET_LANGUAGE}',
                strtoupper($targetLanguage),
                self::progressivePhrasePrompt(),
            ),
            'speakerSaysTemplate' => '{relationshipName} says:',
            'translationTemplate' => 'That means: "{translation}"',
            'vocabIntroTemplate' => '"{translation}" is:',
            'responseIntroTemplate' => 'You respond: "{translation}"',
            'vocabTeachTemplate' => 'Here\'s how you say "{translation}".',
            'progressiveChunkTemplate' => 'Now say: "{translation}".',
            'fullPhraseTemplate' => 'Now the full phrase: "{translation}".',
            'fullPhraseReplayTemplate' => 'Let\'s hear the full phrase again.',
            'noVocabTeachTemplate' => 'Here\'s how you say that.',
            'reviewIntroTemplate' => 'Let\'s review some key phrases.',
            'reviewQuestionTemplate' => 'How do you say: "{translation}"?',
            'outroTemplate' => 'Great work! You\'ve learned some very useful phrases today. Keep practicing, and we\'ll see you in the next lesson.',
        ];
    }

    private static function scenarioIntroPrompt(): string
    {
        return <<<'PROMPT'
You are creating a Pimsleur-style {TARGET_LANGUAGE} language lesson for {NATIVE_LANGUAGE} speakers.{JLPT_INFO}

Based on this dialogue context: "{EPISODE_TITLE}"

Write a compelling 2-3 sentence scenario setup that:
- Starts with "Pretend you are..." or "Imagine you are..."
- Sets up a realistic conversational scenario (e.g., "at a Japanese bar", "talking to a friend", "meeting someone new")
- Explains who you're talking to and what the conversation is about
- Makes the learner feel immersed in the situation
- Write in second person ("you are...", "you're talking to...")

Example: "Pretend you are an American traveler talking to a bartender at a Japanese bar in Hokkaido. He's curious about your recent cycling trip and is asking you questions about your adventure."

Write only the scenario setup, no additional formatting:
PROMPT;
    }

    private static function progressivePhrasePrompt(): string
    {
        return <<<'PROMPT'
You are a language teaching expert using the Pimsleur Method's progressive phrase building technique.

Full sentence: "{FULL_TEXT_L2}" ({FULL_TRANSLATION})

Vocabulary items to combine:
{VOCAB_LIST}

Create 2-4 progressive phrase chunks that build up from the vocabulary items to the full sentence. Each chunk should:
- Combine 2-3 vocabulary items into a meaningful phrase
- Be progressively longer, building toward the full sentence
- Be natural and grammatically correct in {TARGET_LANGUAGE}
- Each phrase should be a stepping stone to the next

Example for "About 2 weeks. It was my first long trip":
1. "long trip" → "long trip"
2. "first long trip" → "first long trip"
3. "about 2 weeks" → "about 2 weeks"
4. "about 2 weeks. first long trip" → "about 2 weeks. it was my first long trip"

Return ONLY a JSON array (no markdown, no explanation):
[
  {"phrase": "...", "translation": "..."},
  {"phrase": "...", "translation": "..."}
]
PROMPT;
    }
}
