<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;

class StudyBrowserCardDisplay
{
    public static function displayTextFor(Card $card): string
    {
        $promptJson = is_array($card->prompt_json) ? $card->prompt_json : [];
        $answerJson = is_array($card->answer_json) ? $card->answer_json : [];

        // ConvoLab browser labels prefer each key across prompt then answer before moving to the next key.
        foreach (['cueText', 'expression', 'clozeText', 'text'] as $key) {
            $promptValue = $promptJson[$key] ?? null;
            if (is_string($promptValue) && trim($promptValue) !== '') {
                return trim($promptValue);
            }

            $answerValue = $answerJson[$key] ?? null;
            if (is_string($answerValue) && trim($answerValue) !== '') {
                return trim($answerValue);
            }
        }

        $frontText = trim($card->front_text ?? '');

        return $frontText !== '' ? $frontText : (string) $card->id;
    }
}
