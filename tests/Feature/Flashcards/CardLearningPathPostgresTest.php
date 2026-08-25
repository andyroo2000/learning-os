<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\LinkCardLearningPathSuccessorAction;
use App\Domain\Flashcards\Exceptions\LearningPathConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CardLearningPathPostgresTest extends TestCase
{
    public const LOCK_HOLD_MICROSECONDS = 400_000;

    public function test_concurrent_successor_links_serialize_on_the_owner_and_only_one_wins(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create(['user_id' => $user->id]);
        $predecessor = Card::factory()->for($deck)->create();
        $firstSuccessor = Card::factory()->for($deck)->create();
        $secondSuccessor = Card::factory()->for($deck)->create();
        $sockets = $this->socketPairs(2);

        DB::disconnect();
        config()->set('database.connections.pgsql_path_worker_a', config('database.connections.pgsql'));
        config()->set('database.connections.pgsql_path_worker_b', config('database.connections.pgsql'));
        $workerInputs = [
            [
                'connection' => 'pgsql_path_worker_a',
                'predecessor_id' => $predecessor->id,
                'successor_id' => $firstSuccessor->id,
                'start_delay_microseconds' => 0,
                'hold_lock' => true,
            ],
            [
                'connection' => 'pgsql_path_worker_b',
                'predecessor_id' => $predecessor->id,
                'successor_id' => $secondSuccessor->id,
                'start_delay_microseconds' => 100_000,
                'hold_lock' => false,
            ],
        ];
        $pids = [];

        try {
            foreach ($workerInputs as $index => $input) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork PostgreSQL learning-path worker.');
                }

                if ($pid === 0) {
                    foreach ($sockets as $socketIndex => $pair) {
                        fclose($pair[0]);

                        if ($socketIndex !== $index) {
                            fclose($pair[1]);
                        }
                    }

                    $this->runWorker($sockets[$index][1], $input);
                }

                $pids[$index] = $pid;
            }

            $results = [];
            foreach ($sockets as $pair) {
                fclose($pair[1]);
                stream_set_timeout($pair[0], 15);
                $payload = trim((string) stream_get_contents($pair[0]));
                $results[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                fclose($pair[0]);
            }

            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status), json_encode($results[$index]));
            }

            $this->assertSame('linked', $results[0]['outcome']);
            $this->assertSame('conflict', $results[1]['outcome']);
            $this->assertSame('learning_path_predecessor_not_tail', $results[1]['reason']);
            $this->assertGreaterThanOrEqual(250, $results[1]['duration_ms']);

            DB::purge();
            $groupId = $predecessor->refresh()->variant_group_id;
            $this->assertNotNull($groupId);
            $this->assertSame(VocabVariantStatus::Locked->value, $firstSuccessor->refresh()->variant_status);
            $this->assertSame($groupId, $firstSuccessor->variant_group_id);
            $this->assertNull($secondSuccessor->refresh()->variant_group_id);
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $user->id)->count());
        } finally {
            foreach ($pids as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }

            foreach ($sockets as $pair) {
                foreach ($pair as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
            }

            DB::purge();
            User::query()->whereKey($user->id)->delete();
        }
    }

    /** @return list<array{0: resource, 1: resource}> */
    private function socketPairs(int $count): array
    {
        return array_map(function (): array {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($pair === false) {
                throw new RuntimeException('Unable to create PostgreSQL learning-path worker sockets.');
            }

            return $pair;
        }, range(1, $count));
    }

    /**
     * @param  resource  $socket
     * @param  array{connection: string, predecessor_id: string, successor_id: string, start_delay_microseconds: int, hold_lock: bool}  $input
     */
    private function runWorker($socket, array $input): never
    {
        try {
            usleep($input['start_delay_microseconds']);
            DB::setDefaultConnection($input['connection']);
            DB::connection()->statement("SET statement_timeout = '10s'");
            $predecessor = Card::query()->findOrFail($input['predecessor_id']);
            $successor = Card::query()->findOrFail($input['successor_id']);
            $sync = $input['hold_lock']
                ? new class extends RecordSyncFeedEntryAction
                {
                    private bool $held = false;

                    public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                    {
                        $entry = parent::handle($data);

                        if (! $this->held) {
                            $this->held = true;
                            usleep(CardLearningPathPostgresTest::LOCK_HOLD_MICROSECONDS);
                        }

                        return $entry;
                    }
                }
            : app(RecordSyncFeedEntryAction::class);
            $startedAt = hrtime(true);

            try {
                (new LinkCardLearningPathSuccessorAction($sync))->handle($predecessor, $successor);
                $result = [
                    'outcome' => 'linked',
                    'reason' => null,
                    'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                ];
            } catch (LearningPathConflictException $exception) {
                $result = [
                    'outcome' => 'conflict',
                    'reason' => $exception->reason(),
                    'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                ];
            }

            fwrite($socket, json_encode($result, JSON_THROW_ON_ERROR));
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, json_encode([
                'outcome' => 'error',
                'reason' => $exception::class.': '.$exception->getMessage(),
                'duration_ms' => 0,
            ]));
            fclose($socket);
            exit(1);
        }
    }
}
