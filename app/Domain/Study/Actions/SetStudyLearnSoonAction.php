<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Support\StudyPileAccess;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SetStudyLearnSoonAction
{
    public function __construct(private readonly RecordSyncFeedEntryAction $recordSync) {}

    public function handle(int $userId, array $cardIds, bool $learnSoon): int
    {
        Validator::make(['cardIds' => $cardIds], [
            'cardIds' => ['required', 'array', 'min:1', 'max:100'],
            'cardIds.*' => ['required', 'string', 'ulid'],
        ])->validate();
        $cardIds = array_unique(array_map(StudyPileAccess::id(...), $cardIds));
        sort($cardIds);

        return DB::transaction(function () use ($userId, $cardIds, $learnSoon): int {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $changed = 0;
            foreach ($cardIds as $id) {
                $card = Card::query()->whereKey($id)
                    ->whereHas('deck', fn ($query) => $query->where('user_id', $userId))
                    ->lockForUpdate()->firstOrFail();
                $changed += $this->update($card, $userId, $learnSoon);
            }

            return $changed;
        });
    }

    private function update(Card $card, int $userId, bool $learnSoon): int
    {
        if ((bool) $card->learn_soon === $learnSoon) {
            return 0;
        }
        if ($card->study_status !== CardStudyStatus::New) {
            throw ValidationException::withMessages(['cardIds' => ['Learn soon applies to new cards.']]);
        }
        $card->learn_soon = $learnSoon;
        $card->save();
        $this->recordSync->handle(RecordSyncFeedEntryData::fromInput(
            userId: $userId,
            domain: CardSyncPayload::DOMAIN,
            resourceType: CardSyncPayload::RESOURCE_TYPE,
            resourceId: $card->id,
            operation: 'update',
            payload: CardSyncPayload::fromCard($card),
        ));

        return 1;
    }
}
