<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;

final class StudyCardAudio
{
    /**
     * Resolve the card's one logical audio reference from legacy side-specific payloads.
     *
     * @return array<string, mixed>|null
     */
    public static function reference(Card $card): ?array
    {
        $prompt = is_array($card->prompt_json) ? $card->prompt_json : [];
        $answer = is_array($card->answer_json) ? $card->answer_json : [];

        foreach ([$prompt['cueAudio'] ?? null, $answer['answerAudio'] ?? null] as $reference) {
            if (is_array($reference) && $reference !== [] && ! array_is_list($reference)) {
                return $reference;
            }
        }

        return null;
    }

    public static function source(Card $card): string
    {
        $storedSource = $card->answer_audio_source;
        if (is_string($storedSource) && in_array($storedSource, ['generated', 'imported', 'missing'], true)) {
            return $storedSource;
        }

        $source = self::reference($card)['source'] ?? null;

        return is_string($source) && in_array($source, ['generated', 'imported'], true)
            ? $source
            : 'missing';
    }
}
