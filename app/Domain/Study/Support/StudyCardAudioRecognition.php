<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantKind;

final class StudyCardAudioRecognition
{
    private const PROMPT_TEXT_KEYS = [
        'cueText',
        'cueReading',
        'cueMeaning',
        'clozeText',
        'clozeDisplayText',
        'clozeAnswerText',
        'clozeHint',
        'clozeResolvedHint',
        'text',
    ];

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $prompt
     */
    public static function hasAudioOnlyPrompt(Card $card, array $prompt): bool
    {
        $cueAudio = $prompt['cueAudio'] ?? null;
        if ($card->card_type !== CardType::Recognition) {
            return false;
        }

        foreach (self::PROMPT_TEXT_KEYS as $key) {
            $value = $prompt[$key] ?? null;
            if ((is_string($value) && trim($value) !== '')
                || (is_array($value) && $value !== [])) {
                return false;
            }
        }

        if ($cueAudio !== null) {
            return is_array($cueAudio) && $cueAudio !== [];
        }

        // Newly committed listening cards have an intentionally empty prompt until their
        // generated answer audio is copied to cueAudio by the first regeneration.
        return in_array($card->variant_kind, [
            VocabVariantKind::SentenceAudioRecognition->value,
            VocabVariantKind::WordAudioRecognition->value,
        ], true);
    }
}
