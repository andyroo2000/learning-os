<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Support\CardBookSource;
use App\Domain\Study\Support\StudyPileAccess;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AssignStudyCardSourcesAction
{
    public function __construct(private readonly RecordSyncFeedEntryAction $recordSync) {}

    public function handle(int $userId, array $cardIds, ?array $bookSource): int
    {
        Validator::make(['cardIds' => $cardIds], [
            'cardIds' => ['required', 'array', 'min:1', 'max:100'],
            'cardIds.*' => ['required', 'string', 'ulid'],
        ])->validate();
        $cardIds = array_unique(array_map(StudyPileAccess::id(...), $cardIds));
        sort($cardIds);
        $source = CardBookSource::normalized($bookSource);

        return DB::transaction(function () use ($userId, $cardIds, $source): int {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            CardBookSource::assertOwned($userId, $source);
            $changed = 0;
            foreach ($cardIds as $id) {
                $card = Card::query()->whereKey($id)
                    ->whereHas('deck', fn ($query) => $query->where('user_id', $userId))
                    ->lockForUpdate()->firstOrFail();
                $changed += $this->assign($card, $userId, $source);
            }

            return $changed;
        });
    }

    private function assign(Card $card, int $userId, ?array $source): int
    {
        if (CardBookSource::payload($card) === $source) {
            return 0;
        }
        CardBookSource::apply($card, $source);
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
