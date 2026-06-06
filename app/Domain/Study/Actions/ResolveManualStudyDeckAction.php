<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Actions\CreateDeckAction;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

class ResolveManualStudyDeckAction
{
    public const DEFAULT_DECK_NAME = 'ConvoLab Study Cards';

    public const DEFAULT_DECK_DESCRIPTION = 'Cards created directly in the study app.';

    public function __construct(
        private readonly CreateDeckAction $createDeck,
    ) {}

    /**
     * Call inside an outer transaction when the returned deck is used for card creation.
     * The local transaction becomes a savepoint there, so deck resolution and card creation
     * stay atomic through the caller's commit.
     */
    public function handle(int $userId): Deck
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Manual study deck resolution must run inside an outer transaction.');
        }

        return DB::transaction(function () use ($userId): Deck {
            // A shared read lock blocks concurrent soft-deletes while allowing concurrent
            // card creates against an existing manual deck. Reserve the user row lock for
            // first-use creation races where no manual deck exists yet.
            $existingDeck = $this->activeManualDeckQuery($userId)
                ->sharedLock()
                ->first();

            if ($existingDeck !== null) {
                return $existingDeck;
            }

            $this->lockDeckOwner($userId);

            $existingDeck = $this->activeManualDeckQuery($userId)
                ->lockForUpdate()
                ->first();

            if ($existingDeck !== null) {
                return $existingDeck;
            }

            return $this->createDeck->handle(CreateDeckData::fromInput(
                userId: $userId,
                name: self::DEFAULT_DECK_NAME,
                description: self::DEFAULT_DECK_DESCRIPTION,
                isManualStudyDeck: true,
            ))->deck;
        });
    }

    /**
     * @return Builder<Deck>
     */
    private function activeManualDeckQuery(int $userId): Builder
    {
        // A soft-deleted manual deck represents an intentional deck deletion. The next manual
        // create starts a fresh active deck instead of reviving the deleted deck's old cards.
        return Deck::query()
            ->where('user_id', $userId)
            ->whereNull('course_id')
            ->where('is_manual_study_deck', true);
    }

    private function lockDeckOwner(int $userId): void
    {
        // When called inside the store-card controller transaction, this nested lock is held
        // until the outer card-create transaction commits.
        $lockedUserId = DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->value('id');

        if ($lockedUserId === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }
    }
}
