<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Exceptions\DeckConflictException;
use App\Domain\Flashcards\Exceptions\DeckCourseNotFoundException;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\CreateDeckResult;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\IntegrityConstraintViolation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class CreateDeckAction
{
    /** @internal Test-only race seam; see tests/Feature/Flashcards/CreateDeckActionTest.php. */
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?Closure $afterClientIdUniqueConflict = null,
    ) {
        if ($afterClientIdUniqueConflict !== null && ! app()->runningUnitTests()) {
            throw new LogicException('Deck creation race hooks may only be used in tests.');
        }
    }

    public function handle(CreateDeckData $data): CreateDeckResult
    {
        if ($data->name === '') {
            throw new InvalidArgumentException('Deck name is required.');
        }

        if ($data->id !== null && ! Str::isUlid($data->id)) {
            throw new InvalidArgumentException('Deck ID must be a valid ULID.');
        }

        if ($data->courseId !== null && ! Str::isUlid($data->courseId)) {
            throw new InvalidArgumentException('Deck course ID must be a valid ULID.');
        }

        $description = self::normalizedDescription($data->description);
        $courseId = $this->ownedCourseId($data);

        if ($data->id !== null) {
            $existingDeck = Deck::withTrashed()->find($data->id);

            if ($existingDeck !== null) {
                return CreateDeckResult::existing($this->matchingExistingDeck($existingDeck, $data, $description, $courseId));
            }
        }

        return $this->createNewDeck($data, $description, $courseId);
    }

    /**
     * Uses manual transaction control so primary-key race recovery can roll back the
     * failed insert before refetching the winning deck on PostgreSQL.
     */
    private function createNewDeck(CreateDeckData $data, ?string $description, ?string $courseId): CreateDeckResult
    {
        $deck = new Deck([
            'user_id' => $data->userId,
            'course_id' => $courseId,
            'name' => $data->name,
            'description' => $description,
        ]);

        if ($data->id !== null) {
            $deck->id = $data->id;
        }

        DB::beginTransaction();

        try {
            $deck->save();
            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $deck->user_id,
                    domain: DeckSyncPayload::DOMAIN,
                    resourceType: DeckSyncPayload::RESOURCE_TYPE,
                    resourceId: $deck->id,
                    operation: SyncFeedOperation::Create->value,
                    payload: DeckSyncPayload::fromDeck($deck),
                ),
            );
        } catch (QueryException $exception) {
            DB::rollBack();

            if ($data->id === null || ! IntegrityConstraintViolation::matchesPrimaryKey($exception, 'decks')) {
                throw $exception;
            }

            // Covers a retry race where another request inserts this client-generated ULID
            // between the pre-check above and this save attempt.
            if ($this->afterClientIdUniqueConflict !== null) {
                ($this->afterClientIdUniqueConflict)($data, $exception);
            }

            $existingDeck = Deck::withTrashed()->find($data->id);

            if ($existingDeck === null) {
                throw $exception;
            }

            return CreateDeckResult::existing($this->matchingExistingDeck($existingDeck, $data, $description, $courseId));
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        DB::commit();

        return CreateDeckResult::created($deck);
    }

    private static function normalizedDescription(?string $description): ?string
    {
        return $description === '' ? null : $description;
    }

    private function ownedCourseId(CreateDeckData $data): ?string
    {
        if ($data->courseId === null) {
            return null;
        }

        $courseExists = Course::query()
            ->whereKey($data->courseId)
            ->where('user_id', $data->userId)
            ->exists();

        if (! $courseExists) {
            throw new DeckCourseNotFoundException($data->courseId);
        }

        return $data->courseId;
    }

    private function matchingExistingDeck(Deck $deck, CreateDeckData $data, ?string $description, ?string $courseId): Deck
    {
        if ($deck->trashed()) {
            // Deleted IDs remain reserved. The owning client gets a deletion signal
            // regardless of submitted metadata; other users still get a hidden 404.
            throw DeckConflictException::deleted($deck);
        }

        // Cross-user conflicts are still represented here so the HTTP layer can
        // hide them behind a 404 without coupling this action to status codes.
        if (
            $deck->user_id !== $data->userId
            || $deck->course_id !== $courseId
            || $deck->name !== $data->name
            || $deck->description !== $description
        ) {
            throw DeckConflictException::conflict($deck);
        }

        return $deck;
    }
}
