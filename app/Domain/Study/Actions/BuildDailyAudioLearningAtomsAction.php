<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Support\DailyAudioPracticeText;
use Illuminate\Support\Collection;

class BuildDailyAudioLearningAtomsAction
{
    /**
     * @param  iterable<int, Card>  $cards
     * @return Collection<int, DailyAudioLearningAtom>
     */
    public function handle(iterable $cards): Collection
    {
        return collect($cards)
            ->map(fn (Card $card): ?DailyAudioLearningAtom => $this->atom($card))
            ->filter()
            ->values();
    }

    private function atom(Card $card): ?DailyAudioLearningAtom
    {
        $prompt = is_array($card->prompt_json) ? $card->prompt_json : [];
        $answer = is_array($card->answer_json) ? $card->answer_json : [];
        $rawFields = is_array($card->convolab_note_raw_fields_json)
            ? $card->convolab_note_raw_fields_json
            : [];

        $targetText = DailyAudioPracticeText::first(
            $prompt['clozeAnswerText'] ?? null,
            $answer['expression'] ?? null,
            $answer['restoredText'] ?? null,
            $prompt['cueText'] ?? null,
            $rawFields['AnswerExpression'] ?? null,
            $rawFields['Expression'] ?? null,
            $rawFields['Text'] ?? null,
        );
        if ($targetText === null) {
            return null;
        }

        $englishCandidates = [
            $answer['meaning'] ?? null,
            $answer['sentenceEn'] ?? null,
            $prompt['cueMeaning'] ?? null,
            $rawFields['Meaning'] ?? null,
            $rawFields['English'] ?? null,
            $rawFields['Translation'] ?? null,
        ];
        $english = DailyAudioPracticeText::firstEnglish(...$englishCandidates)
            ?? DailyAudioPracticeText::first(...$englishCandidates)
            ?? $targetText;

        return new DailyAudioLearningAtom(
            cardId: $card->clientId(),
            cardType: $card->card_type->value,
            targetText: $targetText,
            reading: DailyAudioPracticeText::first(
                $answer['expressionReading'] ?? null,
                $answer['restoredTextReading'] ?? null,
                $prompt['cueReading'] ?? null,
                $rawFields['Reading'] ?? null,
            ),
            english: $english,
            exampleJp: DailyAudioPracticeText::first(
                $answer['sentenceJp'] ?? null,
                $answer['restoredText'] ?? null,
                $prompt['clozeDisplayText'] ?? null,
            ),
            exampleEn: DailyAudioPracticeText::first($answer['sentenceEn'] ?? null),
            deckName: is_string($card->source_deck_name) && trim($card->source_deck_name) !== ''
                ? trim($card->source_deck_name)
                : null,
            noteType: is_string($card->source_notetype_name) && trim($card->source_notetype_name) !== ''
                ? trim($card->source_notetype_name)
                : null,
        );
    }
}
