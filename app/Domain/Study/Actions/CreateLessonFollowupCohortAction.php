<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardSourceKind;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Exceptions\LessonFollowupCohortConflictException;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateLessonFollowupCohortAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * @param  list<string>  $cardIds
     */
    public function handle(int $userId, string $cohortId, array $cardIds, ?string $label): CardIntroductionCohort
    {
        sort($cardIds);

        return DB::transaction(function () use ($cardIds, $cohortId, $label, $userId): CardIntroductionCohort {
            User::query()->select('id')->whereKey($userId)->lockForUpdate()->firstOrFail();

            $existing = CardIntroductionCohort::query()
                ->whereKey($cohortId)
                ->where('user_id', $userId)
                ->with(['cards' => fn ($query) => $query->orderBy('id')])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->resolveReplay($existing, $cardIds, $label);
            }

            if (CardIntroductionCohort::query()->whereKey($cohortId)->exists()) {
                throw (new ModelNotFoundException)->setModel(CardIntroductionCohort::class, [$cohortId]);
            }

            $cards = Card::query()
                ->whereIn('id', $cardIds)
                ->whereHas('deck', fn ($query) => $query->where('user_id', $userId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertCardsAvailable($cards, $cardIds);

            $cohort = new CardIntroductionCohort;
            $cohort->id = $cohortId;
            $cohort->user_id = $userId;
            $cohort->source_kind = CardSourceKind::LessonFollowup;
            $cohort->label = $label;
            $cohort->source_reference = 'lesson-followup:'.$cohortId;
            $cohort->saveOrFail();

            $priorityUntil = $cohort->created_at->copy()->addWeek();
            foreach ($cards as $card) {
                $card->source_kind = CardSourceKind::LessonFollowup->value;
                $card->introduction_cohort_id = $cohort->id;
                $card->selection_policy = CardSelectionPolicy::ReviewSoon;
                $card->priority_until = $priorityUntil;
                $card->introduction_available_at = null;
                $card->saveOrFail();

                $this->recordSyncFeedEntry->handle(RecordSyncFeedEntryData::fromInput(
                    userId: $userId,
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($card),
                ));
            }

            return $cohort->setRelation('cards', $cards);
        });
    }

    /** @param Collection<int, Card> $cards @param list<string> $cardIds */
    private function assertCardsAvailable(Collection $cards, array $cardIds): void
    {
        if ($cards->pluck('id')->all() !== $cardIds
            || $cards->contains(fn (Card $card): bool => ($card->study_status ?? CardStudyStatus::New) !== CardStudyStatus::New
                || ! $card->isProgressionAvailable()
                || $card->new_queue_position === null
                || $card->introduction_cohort_id !== null
                || ! in_array($card->source_kind, [null, '', CardSourceKind::Manual->value], true))) {
            throw LessonFollowupCohortConflictException::cardsUnavailable();
        }
    }

    /** @param list<string> $cardIds */
    private function resolveReplay(
        CardIntroductionCohort $cohort,
        array $cardIds,
        ?string $label,
    ): CardIntroductionCohort {
        if ($cohort->source_kind !== CardSourceKind::LessonFollowup
            || $cohort->label !== $label
            || $cohort->cards->pluck('id')->all() !== $cardIds) {
            throw LessonFollowupCohortConflictException::replayMismatch();
        }

        return $cohort;
    }
}
