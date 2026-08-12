<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Actions\SetCardDueAction;
use App\Domain\Flashcards\Actions\UpdateCardStudyStatusAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CardStudyMutationLockPostgresTest extends TestCase
{
    private const LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_set_due_waits_for_a_concurrent_delete_and_does_not_follow_its_tombstone(): void
    {
        $this->assertStudyMutationRejectsConcurrentDelete('set_due');
    }

    public function test_status_update_waits_for_a_concurrent_delete_and_does_not_follow_its_tombstone(): void
    {
        $this->assertStudyMutationRejectsConcurrentDelete('status');
    }

    public function test_set_due_waits_for_a_concurrent_review_and_uses_its_scheduler_state(): void
    {
        $this->assertStudyMutationPreservesConcurrentReview('set_due');
    }

    public function test_status_update_waits_for_a_concurrent_review_and_uses_its_scheduler_state(): void
    {
        $this->assertStudyMutationPreservesConcurrentReview('status');
    }

    private function assertStudyMutationRejectsConcurrentDelete(string $mutation): void
    {
        $this->requirePostgresConcurrency();

        [$user, $course, $deck, $card] = $this->fixtures();
        $staleCard = Card::query()->with('deck')->findOrFail($card->id);
        $sockets = $this->sockets($mutation, 'delete');
        $pid = $this->forkWorker(
            $sockets,
            fn ($socket): never => $this->runDeleteWorker($socket, $card->id),
        );

        try {
            $this->assertSame('mutated', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            try {
                $this->performStudyMutation(
                    $mutation,
                    $staleCard,
                    studyStatus: CardStudyStatus::New,
                );
                $this->fail('Expected the stale study mutation to reject the deleted card.');
            } catch (ModelNotFoundException) {
                // Delete remains the terminal operation for this card identity.
            }

            $this->assertWaitedForLock($startedAt, $mutation, 'deletion');
            $this->assertWorkerCommitted($pid, $sockets[0]);
            $workerCompleted = true;

            $deletedCard = Card::query()->withTrashed()->findOrFail($card->id);
            $this->assertTrue($deletedCard->trashed());
            $this->assertSame(CardStudyStatus::New, $deletedCard->study_status);
            $this->assertNull($deletedCard->due_at);
            $this->assertNull($deletedCard->last_reviewed_at);
            $this->assertSame(
                [SyncFeedOperation::Delete],
                SyncFeedEntry::query()
                    ->where('user_id', $user->id)
                    ->where('resource_type', 'card')
                    ->where('resource_id', $card->id)
                    ->orderBy('checkpoint')
                    ->pluck('operation')
                    ->all(),
            );
        } finally {
            fclose($sockets[0]);

            if (! isset($workerCompleted)) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user, $course, $deck, $card);
        }
    }

    private function assertStudyMutationPreservesConcurrentReview(string $mutation): void
    {
        $this->requirePostgresConcurrency();

        [$user, $course, $deck, $card] = $this->fixtures();
        $staleCard = Card::query()->with('deck')->findOrFail($card->id);
        $reviewedAt = Carbon::parse('2026-08-12T14:00:00Z');
        $sockets = $this->sockets($mutation, 'review');
        $pid = $this->forkWorker(
            $sockets,
            fn ($socket): never => $this->runReviewWorker($socket, $card->id, $reviewedAt),
        );

        try {
            $this->assertSame('mutated', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);
            $result = $this->performStudyMutation($mutation, $staleCard);

            $this->assertWaitedForLock($startedAt, $mutation, 'review');
            $this->assertWorkerCommitted($pid, $sockets[0]);
            $workerCompleted = true;

            $updatedCard = $result->card->refresh();
            $this->assertSame($reviewedAt->toJSON(), $updatedCard->last_reviewed_at?->toJSON());
            $this->assertSame(1, $updatedCard->scheduler_state['reps']);
            $this->assertSame($reviewedAt->toJSON(), $updatedCard->scheduler_state['last_review']);

            if ($mutation === 'set_due') {
                $this->assertSame(CardStudyStatus::Learning, $updatedCard->study_status);
                $this->assertSame('2026-08-20T14:00:00.000000Z', $updatedCard->due_at?->toJSON());
                $this->assertSame('2026-08-20T14:00:00.000000Z', $updatedCard->scheduler_state['due']);
                $this->assertSame(1, $updatedCard->scheduler_state['state']);
            } else {
                $this->assertSame(CardStudyStatus::Suspended, $updatedCard->study_status);
                $this->assertSame(1, $updatedCard->scheduler_state['state']);
            }

            $cardUpdates = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->where('resource_type', 'card')
                ->where('resource_id', $card->id)
                ->where('operation', SyncFeedOperation::Update->value)
                ->orderBy('checkpoint')
                ->get();
            $this->assertCount(2, $cardUpdates);

            $reviewUpdate = $cardUpdates->first();
            $lastCardUpdate = $cardUpdates->last();
            $this->assertNotNull($reviewUpdate);
            $this->assertNotNull($lastCardUpdate);
            $this->assertSame($reviewedAt->toJSON(), $reviewUpdate->payload['last_reviewed_at']);
            $this->assertSame($reviewedAt->toJSON(), $lastCardUpdate->payload['last_reviewed_at']);
            $this->assertSame(1, $lastCardUpdate->payload['scheduler_state']['reps']);
        } finally {
            fclose($sockets[0]);

            if (! isset($workerCompleted)) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user, $course, $deck, $card);
        }
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');
    }

    /** @return array{User, Course, Deck, Card} */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::New,
            'new_queue_position' => 1,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ]);

        return [$user, $course, $deck, $card];
    }

    /** @return array{0: resource, 1: resource} */
    private function sockets(string $mutation, string $concurrentAction): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException("Unable to create PostgreSQL {$mutation}/{$concurrentAction} sockets.");
        }

        return $sockets;
    }

    /**
     * @param  array{0: resource, 1: resource}  $sockets
     * @param  callable(resource): never  $worker
     */
    private function forkWorker(array $sockets, callable $worker): int
    {
        DB::disconnect();
        config()->set('database.connections.pgsql_card_study_worker', config('database.connections.pgsql'));
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL card-study worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $worker($sockets[1]);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        return $pid;
    }

    /** @param resource $socket */
    private function runDeleteWorker($socket, string $cardId): never
    {
        $this->runWorker($socket, function () use ($cardId): void {
            $card = Card::query()->findOrFail($cardId);
            app(DeleteCardAction::class)->handle($card);
        });
    }

    /** @param resource $socket */
    private function runReviewWorker($socket, string $cardId, Carbon $reviewedAt): never
    {
        $this->runWorker($socket, function () use ($cardId, $reviewedAt): void {
            app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
                cardId: $cardId,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
                clientEventId: 'card-study-lock-review',
                deviceId: 'card-study-lock-device',
                clientCreatedAt: $reviewedAt,
            ));
        });
    }

    /**
     * @param  resource  $socket
     * @param  callable(): void  $mutation
     */
    private function runWorker($socket, callable $mutation): never
    {
        try {
            DB::setDefaultConnection('pgsql_card_study_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($mutation, $socket): void {
                $mutation();
                fwrite($socket, "mutated\n");
                fflush($socket);
                usleep(self::LOCK_HOLD_MICROSECONDS);
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

    private function performStudyMutation(
        string $mutation,
        Card $card,
        CardStudyStatus $studyStatus = CardStudyStatus::Suspended,
    ): UpdateCardResult {
        return match ($mutation) {
            'set_due' => app(SetCardDueAction::class)->handle(
                card: $card,
                mode: 'custom_date',
                dueAt: '2026-08-20T14:00:00Z',
                now: Carbon::parse('2026-08-12T14:05:00Z'),
            ),
            'status' => app(UpdateCardStudyStatusAction::class)->handle(
                $card,
                $studyStatus,
            ),
            default => throw new RuntimeException("Unsupported card study mutation {$mutation}."),
        };
    }

    private function assertWaitedForLock(float $startedAt, string $mutation, string $concurrentAction): void
    {
        $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
        $this->assertGreaterThanOrEqual(
            350,
            $lockWaitMilliseconds,
            "Expected {$mutation} to wait for the concurrent {$concurrentAction} transaction.",
        );
    }

    /** @param resource $socket */
    private function assertWorkerCommitted(int $pid, $socket): void
    {
        pcntl_waitpid($pid, $status);
        $workerMessage = trim((string) fgets($socket));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
        $this->assertSame('committed', $workerMessage);
    }

    private function cleanup(User $user, Course $course, Deck $deck, Card $card): void
    {
        DB::purge();
        SyncFeedEntry::query()->where('user_id', $user->id)->delete();
        DB::table('card_review_events')->where('card_id', $card->id)->delete();
        Card::query()->withTrashed()->whereKey($card->id)->forceDelete();
        Deck::query()->withTrashed()->whereKey($deck->id)->forceDelete();
        Course::query()->withTrashed()->whereKey($course->id)->forceDelete();
        User::query()->whereKey($user->id)->delete();
    }
}
