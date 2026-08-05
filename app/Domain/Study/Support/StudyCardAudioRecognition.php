<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;

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
        if ($card->card_type !== CardType::Recognition || ! is_array($cueAudio) || $cueAudio === []) {
            return false;
        }

        foreach (self::PROMPT_TEXT_KEYS as $key) {
            $value = $prompt[$key] ?? null;
            if ((is_string($value) && trim($value) !== '')
                || (is_array($value) && $value !== [])) {
                return false;
            }
        }

        return true;
    }
}
