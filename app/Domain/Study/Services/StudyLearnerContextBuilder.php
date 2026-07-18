<?php

namespace App\Domain\Study\Services;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use Throwable;

class StudyLearnerContextBuilder
{
    private const CARD_LIMIT = 12;

    public function build(int $userId): ?string
    {
        try {
            $cards = Card::query()
                ->ownedByActiveDeck($userId)
                ->whereIn('cards.study_status', [
                    CardStudyStatus::Learning->value,
                    CardStudyStatus::Relearning->value,
                    CardStudyStatus::Review->value,
                ])
                ->orderByDesc('cards.last_reviewed_at')
                ->orderByDesc('cards.updated_at')
                ->limit(self::CARD_LIMIT)
                ->get([
                    'cards.card_type',
                    'cards.study_status',
                    'cards.prompt_json',
                    'cards.answer_json',
                    'cards.source_lapses',
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $lines = $cards
            ->map(function (Card $card): ?string {
                $label = $this->recordText($card->answer_json) ?? $this->recordText($card->prompt_json);
                if ($label === null) {
                    return null;
                }

                $lapses = (int) ($card->source_lapses ?? 0);
                $lapseLabel = $lapses > 0 ? " ({$lapses} lapses)" : '';

                return "- {$card->card_type->value}/{$card->study_status->value}{$lapseLabel}: {$label}";
            })
            ->filter()
            ->values();

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    /** @param array<string, mixed>|null $record */
    private function recordText(?array $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $text = $this->firstString($record, [
            'expression',
            'restoredText',
            'cueText',
            'clozeText',
        ]);
        $meaning = $this->firstString($record, ['meaning', 'cueMeaning']);
        $parts = array_values(array_filter([$text, $meaning]));

        return $parts === [] ? null : implode(' - ', $parts);
    }

    /** @param array<string, mixed> $record @param list<string> $keys */
    private function firstString(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $record[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
