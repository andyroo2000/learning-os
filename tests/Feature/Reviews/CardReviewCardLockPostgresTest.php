<?php

namespace Tests\Feature\Reviews;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Exceptions\CardReviewEventConflictException;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CardReviewCardLockPostgresTest extends TestCase
{
    public const LOCK_HOLD_MICROSECONDS = 400_000;

    public function test_reversed_overlapping_batch_transactions_serialize_without_deadlocking(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $firstCard = Card::factory()->for($deck)->create(['id' => '01k00000000000000000000001']);
        $secondCard = Card::factory()->for($deck)->create(['id' => '01K00000000000000000000002']);
        $cardIds = [$firstCard->id, $secondCard->id];

        try {
            $results = $this->runConcurrentWorkers([
                [
                    'card_ids' => [$secondCard->id, $firstCard->id],
                    'event_prefix' => 'postgres-lock-a',
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'start_delay_microseconds' => 0,
                ],
                [
                    'card_ids' => [$firstCard->id, $secondCard->id],
                    'event_prefix' => 'postgres-lock-b',
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'start_delay_microseconds' => 100_000,
                ],
            ]);

            $this->assertSame(['created', 'created'], array_column($results, 'outcome'));
            $this->assertSame([$secondCard->id, $firstCard->id], $results[0]['card_ids']);
            $this->assertSame([$firstCard->id, $secondCard->id], $results[1]['card_ids']);
            $this->assertGreaterThanOrEqual(
                250,
                max($results[0]['lock_wait_ms'], $results[1]['lock_wait_ms']),
                'Expected one overlapping transaction to wait for the canonical card lock sequence.',
            );

            $this->assertDatabaseCount('card_review_events', 4);
            $this->assertSame('2026-05-27T09:20:00.000000Z', $firstCard->refresh()->last_reviewed_at->toJSON());
            $this->assertSame('2026-05-27T09:20:00.000000Z', $secondCard->refresh()->last_reviewed_at->toJSON());
        } finally {
            $this->deleteFixtures($user, $course, $deck, $cardIds);
        }
    }

    public function test_a_late_concurrent_review_is_rejected_after_waiting_for_the_card_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();

        try {
            $results = $this->runConcurrentWorkers([
                [
                    'card_ids' => [$card->id],
                    'event_prefix' => 'postgres-chronology-later',
                    'reviewed_at' => '2026-05-27T09:20:00Z',
                    'start_delay_microseconds' => 0,
                ],
                [
                    'card_ids' => [$card->id],
                    'event_prefix' => 'postgres-chronology-older',
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'start_delay_microseconds' => 100_000,
                ],
            ]);

            $this->assertSame(['created', 'conflict'], array_column($results, 'outcome'));
            $this->assertSame('card_review_event_out_of_order', $results[1]['reason']);
            $this->assertGreaterThanOrEqual(
                250,
                $results[1]['lock_wait_ms'],
                'Expected the late writer to validate chronology only after the winning card lock committed.',
            );
            $this->assertDatabaseCount('card_review_events', 1);
            $this->assertSame('2026-05-27T09:20:00.000000Z', $card->refresh()->last_reviewed_at->toJSON());
        } finally {
            $this->deleteFixtures($user, $course, $deck, [$card->id]);
        }
    }

    public function test_concurrent_retries_with_the_same_review_id_apply_card_state_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();
        $reviewEventId = strtolower((string) str()->ulid());

        try {
            $results = $this->runConcurrentWorkers([
                [
                    'card_ids' => [$card->id],
                    'event_prefix' => 'postgres-idempotency-a',
                    'event_id' => $reviewEventId,
                    'mode' => 'single',
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'start_delay_microseconds' => 0,
                ],
                [
                    'card_ids' => [$card->id],
                    'event_prefix' => 'postgres-idempotency-b',
                    'event_id' => $reviewEventId,
                    'mode' => 'single',
                    'reviewed_at' => '2026-05-27T09:15:00Z',
                    'start_delay_microseconds' => 100_000,
                ],
            ]);

            $this->assertSame(['created', 'existing'], array_column($results, 'outcome'));
            $this->assertGreaterThanOrEqual(
                250,
                $results[1]['lock_wait_ms'],
                'Expected the retry to resolve its event identity after the winning card lock committed.',
            );
            $this->assertDatabaseCount('card_review_events', 1);
            $this->assertDatabaseHas('card_review_events', [
                'id' => $reviewEventId,
                'card_id' => $card->id,
                'rating' => CardReviewRating::Good->value,
            ]);
            $this->assertSame(1, $card->refresh()->scheduler_state['reps']);
            $this->assertDatabaseCount('sync_feed_entries', 2);
        } finally {
            $this->deleteFixtures($user, $course, $deck, [$card->id]);
        }
    }

    /**
     * @param  list<array{card_ids: list<string>, event_prefix: string, reviewed_at: string, start_delay_microseconds: int, mode?: string, event_id?: string}>  $workerInputs
     * @return list<array{card_ids: list<string>, lock_wait_ms: int, outcome: string, reason: string|null}>
     */
    private function runConcurrentWorkers(array $workerInputs): array
    {
        $socketPairs = array_map(function (): array {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($pair === false) {
                throw new RuntimeException('Unable to create PostgreSQL concurrency worker sockets.');
            }

            return $pair;
        }, $workerInputs);

        $workerPids = [];

        foreach ($workerInputs as $index => $workerInput) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork PostgreSQL concurrency worker.');
            }

            if ($pid === 0) {
                foreach ($socketPairs as $pairIndex => $pair) {
                    fclose($pair[0]);

                    if ($pairIndex !== $index) {
                        fclose($pair[1]);
                    }
                }

                $this->runWorker($socketPairs[$index][1], $workerInput);
            }

            $workerPids[$index] = $pid;
        }

        foreach ($socketPairs as $pair) {
            fclose($pair[1]);
            stream_set_timeout($pair[0], 15);
        }

        $readyMessages = array_map(
            fn (array $pair): string => trim((string) fgets($pair[0])),
            $socketPairs,
        );

        foreach ($socketPairs as $pair) {
            fwrite($pair[0], "go\n");
            fflush($pair[0]);
        }

        $exitStatuses = [];

        foreach ($workerPids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            $exitStatuses[$index] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        }

        $resultMessages = array_map(
            fn (array $pair): string => trim((string) fgets($pair[0])),
            $socketPairs,
        );

        foreach ($socketPairs as $pair) {
            fclose($pair[0]);
        }

        $this->assertSame(['ready', 'ready'], $readyMessages);
        $this->assertSame([0, 0], $exitStatuses, 'A PostgreSQL worker failed: '.implode(' | ', $resultMessages));

        return array_map(function (string $message): array {
            try {
                $result = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->fail('PostgreSQL worker returned invalid JSON: '.$message.' ('.$e->getMessage().')');
            }

            $this->assertIsArray($result);
            $this->assertTrue($result['ok'] ?? false, 'PostgreSQL worker failed: '.($result['error'] ?? 'unknown error'));
            $this->assertIsArray($result['card_ids'] ?? null);
            $this->assertIsInt($result['lock_wait_ms'] ?? null);
            $this->assertContains($result['outcome'] ?? null, ['created', 'existing', 'conflict']);

            return [
                'card_ids' => array_values($result['card_ids']),
                'lock_wait_ms' => $result['lock_wait_ms'],
                'outcome' => $result['outcome'],
                'reason' => $result['reason'] ?? null,
            ];
        }, $resultMessages);
    }

    /**
     * @param  resource  $socket
     * @param  array{card_ids: list<string>, event_prefix: string, reviewed_at: string, start_delay_microseconds: int, mode?: string, event_id?: string}  $workerInput
     */
    private function runWorker($socket, array $workerInput): never
    {
        try {
            DB::purge();
            $connection = DB::connection();
            $connection->statement("SET lock_timeout = '5s'");
            $connection->statement("SET deadlock_timeout = '100ms'");
            $connection->statement("SET statement_timeout = '10s'");

            fwrite($socket, "ready\n");
            fflush($socket);

            if (trim((string) fgets($socket)) !== 'go') {
                throw new RuntimeException('PostgreSQL concurrency worker did not receive its start signal.');
            }

            usleep($workerInput['start_delay_microseconds']);

            try {
                if (($workerInput['mode'] ?? 'batch') === 'single') {
                    $action = new class(app(RecordSyncFeedEntryAction::class)) extends ReviewCardAction
                    {
                        public int $lockWaitMilliseconds = 0;

                        protected function findCardForUpdate(string $cardId): ?Card
                        {
                            $startedAt = microtime(true);
                            $card = parent::findCardForUpdate($cardId);
                            $this->lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
                            usleep(CardReviewCardLockPostgresTest::LOCK_HOLD_MICROSECONDS);

                            return $card;
                        }
                    };
                    $result = $action->handle(ReviewCardData::fromInput(
                        cardId: $workerInput['card_ids'][0],
                        rating: CardReviewRating::Good->value,
                        reviewedAt: $workerInput['reviewed_at'],
                        id: $workerInput['event_id'] ?? null,
                    ));
                    $cardIds = [$result->reviewEvent->card_id];
                    $outcome = $result->wasCreated ? 'created' : 'existing';
                } else {
                    $action = new class(app(RecordSyncFeedEntryAction::class)) extends ReviewCardBatchAction
                    {
                        public int $lockWaitMilliseconds = 0;

                        protected function cardsById(Collection $preparedItems): Collection
                        {
                            $startedAt = microtime(true);
                            $cards = parent::cardsById($preparedItems);
                            $this->lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
                            usleep(CardReviewCardLockPostgresTest::LOCK_HOLD_MICROSECONDS);

                            return $cards;
                        }
                    };
                    $result = $action->handle(array_map(
                        fn (string $cardId, int $index): ReviewCardData => ReviewCardData::fromInput(
                            cardId: $cardId,
                            rating: CardReviewRating::Good->value,
                            reviewedAt: $workerInput['reviewed_at'],
                            clientEventId: $workerInput['event_prefix'].'-'.$index,
                            deviceId: $workerInput['event_prefix'].'-device',
                            clientCreatedAt: $workerInput['reviewed_at'],
                        ),
                        $workerInput['card_ids'],
                        array_keys($workerInput['card_ids']),
                    ));
                    $cardIds = $result->reviewEvents->pluck('card_id')->all();
                    $outcome = 'created';
                }

                $reason = null;
            } catch (CardReviewEventConflictException $e) {
                $cardIds = $workerInput['card_ids'];
                $outcome = 'conflict';
                $reason = $e->reason();
            }

            $this->writeWorkerMessage($socket, [
                'ok' => true,
                'card_ids' => $cardIds,
                'lock_wait_ms' => $action->lockWaitMilliseconds,
                'outcome' => $outcome,
                'reason' => $reason,
            ]);
            fclose($socket);
            exit(0);
        } catch (Throwable $e) {
            $this->writeWorkerMessage($socket, [
                'ok' => false,
                'error' => $e::class.': '.$e->getMessage(),
            ]);
            fclose($socket);
            exit(1);
        }
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $message
     */
    private function writeWorkerMessage($socket, array $message): void
    {
        fwrite($socket, json_encode($message, JSON_THROW_ON_ERROR)."\n");
        fflush($socket);
    }

    /** @param list<string> $cardIds */
    private function deleteFixtures(User $user, Course $course, Deck $deck, array $cardIds): void
    {
        DB::table('sync_feed_entries')->where('user_id', $user->id)->delete();
        DB::table('card_review_events')->whereIn('card_id', $cardIds)->delete();
        DB::table('cards')->whereIn('id', $cardIds)->delete();
        DB::table('decks')->where('id', $deck->id)->delete();
        DB::table('courses')->where('id', $course->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
    }
}
