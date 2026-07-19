<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Services\PitchAccentResolver;
use App\Domain\Study\Exceptions\StudyCardPitchAccentConflictException;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use Illuminate\Support\Facades\DB;

class ResolveStudyCardPitchAccentAction
{
    public function __construct(
        private readonly PitchAccentResolver $resolver,
        private readonly UpdateCardAction $updateCard,
    ) {}

    public function handle(Card $card): Card
    {
        $prompt = $this->payload($card->prompt_json, $card->front_text);
        $answer = $this->payload($card->answer_json, $card->back_text);
        $cached = is_array($answer['pitchAccent'] ?? null) ? $answer['pitchAccent'] : null;

        if (($cached['status'] ?? null) === 'resolved') {
            return $card;
        }

        $snapshotFingerprint = $this->cardFingerprint($card);
        $input = $this->resolverInput($card, $prompt, $answer);
        $pitchAccent = $this->resolver->resolve(
            expression: $input['expression'],
            expressionReading: $input['expressionReading'],
            promptReading: $input['promptReading'],
            answerAudioTextOverride: $this->stringValue($answer, 'answerAudioTextOverride'),
            sentence: $input['sentence'],
            sentenceReading: $this->stringValue($answer, 'sentenceJpKana'),
            cached: $cached,
        );

        return DB::transaction(function () use (
            $card,
            $answer,
            $pitchAccent,
            $snapshotFingerprint,
        ): Card {
            $lockedCard = Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($snapshotFingerprint, $this->cardFingerprint($lockedCard))) {
                throw StudyCardPitchAccentConflictException::cardChanged();
            }

            $nextAnswer = $answer;
            $nextAnswer['pitchAccent'] = $pitchAccent;
            $this->updateCard->handle($lockedCard, UpdateCardData::fromInput(
                frontText: $lockedCard->front_text,
                backText: $lockedCard->back_text,
                hasAnswerJson: true,
                answerJson: $nextAnswer,
            ));

            return $lockedCard->fresh(['deck', 'mediaAssets']) ?? $lockedCard;
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function payload(?array $payload, string $fallbackText): array
    {
        return $payload ?? ['type' => 'text', 'text' => $fallbackText];
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @return array{
     *   expression: ?string,
     *   expressionReading: ?string,
     *   promptReading: ?string,
     *   sentence: ?string
     * }
     */
    private function resolverInput(Card $card, array $prompt, array $answer): array
    {
        $variantTarget = $this->variantTarget($card);
        if ($variantTarget !== null) {
            return [
                'expression' => $variantTarget['word'],
                'expressionReading' => $variantTarget['reading'],
                'promptReading' => null,
                'sentence' => $this->stringValue($answer, 'restoredText')
                    ?? $this->stringValue($answer, 'sentenceJp')
                    ?? $this->stringValue($answer, 'expression'),
            ];
        }

        if ($card->card_type === CardType::Cloze) {
            return [
                'expression' => $this->stripBracketRuby(
                    $this->stringValue($answer, 'restoredText')
                        ?? $this->stringValue($answer, 'restoredTextReading')
                        ?? $this->stringValue($answer, 'expression')
                        ?? $card->back_text,
                ),
                'expressionReading' => $this->stringValue($answer, 'restoredTextReading'),
                'promptReading' => null,
                'sentence' => $this->stringValue($answer, 'restoredText')
                    ?? $this->stringValue($answer, 'sentenceJp'),
            ];
        }

        return [
            'expression' => $this->stringValue($answer, 'expression') ?? $card->back_text,
            'expressionReading' => $this->stringValue($answer, 'expressionReading'),
            'promptReading' => $this->stringValue($prompt, 'cueReading'),
            'sentence' => $this->stringValue($answer, 'sentenceJp'),
        ];
    }

    /**
     * @return array{word: string, reading: ?string}|null
     */
    private function variantTarget(Card $card): ?array
    {
        if (! is_string($card->variant_group_id) || $card->variant_group_id === '') {
            return null;
        }

        $userId = $card->ownerUserId();
        $group = StudyVocabVariantGroup::query()
            ->whereKey($card->variant_group_id)
            ->where('user_id', $userId)
            ->first(['target_word', 'target_reading']);
        if ($group !== null && trim($group->target_word) !== '') {
            return [
                'word' => trim($group->target_word),
                'reading' => is_string($group->target_reading) ? $group->target_reading : null,
            ];
        }

        // Copied Convo cards can retain group IDs even when the source group table was not imported.
        $wordCard = Card::query()
            ->ownedByActiveDeck($userId)
            ->where('cards.variant_group_id', $card->variant_group_id)
            ->whereIn('cards.variant_kind', [
                VocabVariantKind::WordAudioRecognition->value,
                VocabVariantKind::WordTextRecognition->value,
            ])
            ->orderBy('cards.variant_stage')
            ->orderBy('cards.id')
            ->first();
        $wordAnswer = $wordCard?->answer_json;
        if (! is_array($wordAnswer)) {
            return null;
        }

        $word = $this->stringValue($wordAnswer, 'expression');

        return $word === null || trim($word) === ''
            ? null
            : [
                'word' => trim($word),
                'reading' => $this->stringValue($wordAnswer, 'expressionReading'),
            ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function stripBracketRuby(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stripped = preg_replace('/\[[^\]]+]/u', '', $value) ?? '';
        $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? '';
        $stripped = trim($stripped);

        return $stripped === '' ? null : $stripped;
    }

    private function cardFingerprint(Card $card): string
    {
        return hash('sha256', serialize([
            $card->front_text,
            $card->back_text,
            $card->card_type?->value,
            $card->prompt_json,
            $card->answer_json,
            $card->updated_at?->toJSON(),
        ]));
    }
}
