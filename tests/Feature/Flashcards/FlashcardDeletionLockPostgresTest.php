<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Actions\DeleteCourseAction;
use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Actions\CreateDeckAction;
use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Flashcards\Exceptions\DeckCourseNotFoundException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class FlashcardDeletionLockPostgresTest extends TestCase
{
    private const DELETION_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_card_delete_retry_waits_and_does_not_duplicate_the_tombstone(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $card = $this->cardFor($user);

        $this->assertConcurrentRetryIsUnchanged('card', $card, expectedFeedEntries: 1);
    }

    public function test_deck_delete_retry_waits_and_does_not_duplicate_descendant_tombstones(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        Card::factory()->for($deck)->create();

        $this->assertConcurrentRetryIsUnchanged('deck', $deck, expectedFeedEntries: 2);
    }

    public function test_course_delete_retry_waits_and_does_not_duplicate_descendant_tombstones(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        Card::factory()->for($deck)->create();

        $this->assertConcurrentRetryIsUnchanged('course', $course, expectedFeedEntries: 3);
    }

    public function test_deck_deletion_waits_for_a_concurrent_card_delete_and_does_not_repeat_its_tombstone(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $staleDeck = Deck::query()->findOrFail($deck->id);
        $sockets = $this->sockets('card/deck deletion');

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL card deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], 'card', $card->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            $result = app(DeleteDeckAction::class)->handle($staleDeck);

            $this->assertTrue($result->wasDeleted);
            $this->assertWaitedForLock($startedAt, 'Expected deck deletion to wait for direct card deletion.');
            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);

            $entries = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->orderBy('checkpoint')
                ->get();

            $this->assertCount(2, $entries);
            $this->assertSame(['card', 'deck'], $entries->pluck('resource_type')->all());
            $this->assertSame([
                SyncFeedOperation::Delete,
                SyncFeedOperation::Delete,
            ], $entries->pluck('operation')->all());
            $this->assertSame($card->id, $entries[0]->resource_id);
            $this->assertSame($deck->id, $entries[1]->resource_id);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
        }
    }

    public function test_deck_creation_waits_for_a_concurrent_course_delete_and_rejects_the_deleted_parent(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $sockets = $this->sockets('course deletion/deck creation');

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL course deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], 'course', $course->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            try {
                app(CreateDeckAction::class)->handle(CreateDeckData::fromInput(
                    userId: $user->id,
                    name: 'Concurrent deck',
                    courseId: $course->id,
                ));

                $this->fail('Expected deck creation to reject the concurrently deleted course.');
            } catch (DeckCourseNotFoundException) {
                $this->assertWaitedForLock($startedAt, 'Expected deck creation to wait for course deletion.');
            }

            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);
            $this->assertSoftDeleted('courses', ['id' => $course->id]);
            $this->assertDatabaseCount('decks', 0);
            $this->assertDatabaseCount('sync_feed_entries', 1);
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => 'course',
                'resource_id' => $course->id,
                'operation' => SyncFeedOperation::Delete->value,
            ]);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
        }
    }

    public function test_card_creation_waits_for_a_concurrent_deck_delete_and_rejects_the_deleted_parent(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $sockets = $this->sockets('deck deletion/card creation');

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL deck deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], 'deck', $deck->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            try {
                app(CreateCardAction::class)->handle(CreateCardData::fromInput(
                    userId: $user->id,
                    deckId: $deck->id,
                    frontText: 'こんにちは',
                    backText: 'hello',
                ));

                $this->fail('Expected card creation to reject the concurrently deleted deck.');
            } catch (CardValidationException) {
                $this->assertWaitedForLock($startedAt, 'Expected card creation to wait for deck deletion.');
            }

            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);
            $this->assertSoftDeleted('decks', ['id' => $deck->id]);
            $this->assertDatabaseCount('cards', 0);
            $this->assertDatabaseCount('sync_feed_entries', 1);
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => 'deck',
                'resource_id' => $deck->id,
                'operation' => SyncFeedOperation::Delete->value,
            ]);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
        }
    }

    public function test_course_deletion_waits_for_a_concurrent_deck_delete_and_does_not_repeat_descendant_tombstones(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        $staleCourse = Course::query()->findOrFail($course->id);
        $sockets = $this->sockets('deck/course deletion');

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL deck deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], 'deck', $deck->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            $result = app(DeleteCourseAction::class)->handle($staleCourse);

            $this->assertTrue($result->wasDeleted);
            $this->assertWaitedForLock($startedAt, 'Expected course deletion to wait for direct deck deletion.');
            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);

            $entries = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->orderBy('checkpoint')
                ->get();

            $this->assertCount(3, $entries);
            $this->assertSame(['card', 'deck', 'course'], $entries->pluck('resource_type')->all());
            $this->assertSame($card->id, $entries[0]->resource_id);
            $this->assertSame($deck->id, $entries[1]->resource_id);
            $this->assertSame($course->id, $entries[2]->resource_id);
            $this->assertSame(
                [SyncFeedOperation::Delete, SyncFeedOperation::Delete, SyncFeedOperation::Delete],
                $entries->pluck('operation')->all(),
            );
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
        }
    }

    private function assertConcurrentRetryIsUnchanged(
        string $resourceType,
        Card|Deck|Course $model,
        int $expectedFeedEntries,
    ): void {
        $user = $model instanceof Card
            ? User::query()->findOrFail($model->ownerUserId())
            : User::query()->findOrFail($model->user_id);
        $staleModel = $model::query()->findOrFail($model->getKey());
        $sockets = $this->sockets("{$resourceType} deletion");

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Unable to fork the PostgreSQL {$resourceType} deletion worker.");
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], $resourceType, $model->getKey());
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            $result = $this->performDeletion($resourceType, $staleModel);

            $this->assertFalse($result->wasDeleted);
            $this->assertWaitedForLock($startedAt, "Expected {$resourceType} retry to wait for the first deletion.");
            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);
            $entries = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->orderBy('checkpoint')
                ->get();
            $expectedResourceTypes = match ($resourceType) {
                'card' => ['card'],
                'deck' => ['card', 'deck'],
                'course' => ['card', 'deck', 'course'],
            };

            $this->assertCount($expectedFeedEntries, $entries);
            $this->assertSame($expectedResourceTypes, $entries->pluck('resource_type')->all());
            $this->assertSame(
                array_fill(0, $expectedFeedEntries, SyncFeedOperation::Delete),
                $entries->pluck('operation')->all(),
            );
            $this->assertSame(
                1,
                SyncFeedEntry::query()
                    ->where('resource_type', $resourceType)
                    ->where('resource_id', $model->getKey())
                    ->where('operation', SyncFeedOperation::Delete->value)
                    ->count(),
            );
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
        }
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');
    }

    /** @return array{0: resource, 1: resource} */
    private function sockets(string $label): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException("Unable to create PostgreSQL {$label} sockets.");
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function runDeletionWorker($socket, string $resourceType, string $resourceId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $resourceType, $resourceId): void {
                $model = match ($resourceType) {
                    'card' => Card::query()->findOrFail($resourceId),
                    'deck' => Deck::query()->findOrFail($resourceId),
                    'course' => Course::query()->findOrFail($resourceId),
                    default => throw new RuntimeException("Unsupported resource type {$resourceType}."),
                };

                $this->performDeletion($resourceType, $model);

                fwrite($socket, "deleted\n");
                fflush($socket);
                usleep(self::DELETION_LOCK_HOLD_MICROSECONDS);
            });
            fwrite($socket, "committed\n");
            fflush($socket);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, $exception::class.': '.$exception->getMessage()."\n");
            fflush($socket);
            fclose($socket);
            exit(1);
        }
    }

    private function performDeletion(string $resourceType, Card|Deck|Course $model): object
    {
        return match ($resourceType) {
            'card' => app(DeleteCardAction::class)->handle($model),
            'deck' => app(DeleteDeckAction::class)->handle($model),
            'course' => app(DeleteCourseAction::class)->handle($model),
            default => throw new RuntimeException("Unsupported resource type {$resourceType}."),
        };
    }

    private function assertWaitedForLock(float $startedAt, string $message): void
    {
        $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
        $this->assertGreaterThanOrEqual(350, $lockWaitMilliseconds, $message);
    }

    /** @param resource $socket */
    private function waitForWorker(int $pid, $socket): int
    {
        pcntl_waitpid($pid, $status);
        $workerMessage = trim((string) fgets($socket));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame('committed', $workerMessage);

        return pcntl_wexitstatus($status);
    }

    private function cleanup(User $user): void
    {
        SyncFeedEntry::query()->where('user_id', $user->id)->delete();
        Card::query()->withTrashed()->whereHas('deck', fn ($query) => $query->withTrashed()->where('user_id', $user->id))->forceDelete();
        Deck::query()->withTrashed()->where('user_id', $user->id)->forceDelete();
        Course::query()->withTrashed()->where('user_id', $user->id)->forceDelete();
        User::query()->whereKey($user->id)->delete();
    }
}
