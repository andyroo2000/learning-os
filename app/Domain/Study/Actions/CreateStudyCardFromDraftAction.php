<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Flashcards\Results\CreateCardResult;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardPayloadText;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateStudyCardFromDraftAction
{
    public function __construct(
        private readonly ResolveManualStudyDeckAction $resolveManualStudyDeck,
        private readonly CreateCardAction $createCard,
    ) {}

    public function handle(int $userId, string $draftId, string $cardId): CreateCardResult
    {
        if ($userId <= 0) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        $canonicalCardId = CanonicalUlid::normalize($cardId);
        $canonicalDraftId = CanonicalUlid::normalize($draftId);

        // Keep the draft row locked while the final card content snapshot is derived. The draft
        // remains after commit so clients can retry with the same card ID before deleting it.
        return DB::transaction(function () use ($userId, $canonicalDraftId, $canonicalCardId): CreateCardResult {
            $draft = StudyCardDraft::query()
                ->where('user_id', $userId)
                ->whereKey($canonicalDraftId)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                throw StudyCardDraftNotFoundException::notFound();
            }

            if ($draft->status === StudyManualCardDraftStatus::Generating) {
                throw StudyCardDraftConflictException::generatingCannotCreateCard();
            }

            if ($draft->committed_card_id !== null
                && CanonicalUlid::normalize((string) $draft->committed_card_id) !== $canonicalCardId) {
                throw StudyCardDraftConflictException::alreadyCommitted();
            }

            $promptJson = $draft->prompt_json;
            $answerJson = $draft->answer_json;

            $frontText = StudyCardPayloadText::frontText($promptJson)
                ?? throw CardValidationException::missingFrontText();
            $backText = StudyCardPayloadText::backText($answerJson)
                ?? throw CardValidationException::missingBackText();

            $deck = $this->resolveManualStudyDeck->handle($userId);

            $result = $this->createCard->handle(CreateCardData::fromInput(
                userId: $userId,
                deckId: $deck->id,
                frontText: $frontText,
                backText: $backText,
                cardType: $draft->card_type,
                promptJson: $promptJson,
                answerJson: $answerJson,
                id: $canonicalCardId,
            ));

            if ($draft->committed_card_id === null) {
                $draft->committed_card_id = $result->card->id;
                $draft->save();
            }

            return $result;
        });
    }
}
