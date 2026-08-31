<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\PromoteNewCardToFrontAction;
use App\Domain\Flashcards\Actions\ReorderNewCardQueueAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;
use Throwable;

class NewCardQueuePromoteLockPostgresTest extends TestCase
{
    use SetsCardStudyStatus;

    public function test_promote_waits_for_a_concurrent_reorder_and_preserves_its_committed_order(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $targetCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $sockets = $this->sockets();

        DB::disconnect();
        config()->set('database.connections.pgsql_reorder_worker', config('database.connections.pgsql'));
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the new-card queue reorder worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runReorderWorker($sockets[1], $user->id, $firstCard->id, $secondCard->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('reordered', trim((string) fgets($sockets[0])));
            DB::purge();
            $startedAt = microtime(true);

            app(PromoteNewCardToFrontAction::class)->handle($user->id, $targetCard->id);

            $this->assertGreaterThanOrEqual(
                0.35,
                microtime(true) - $startedAt,
                'Promote should wait for the concurrent owner-scoped queue writer.',
            );

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);
            $this->assertSame(0, $targetCard->refresh()->new_queue_position);
            $this->assertSame(1, $secondCard->refresh()->new_queue_position);
            $this->assertSame(2, $firstCard->refresh()->new_queue_position);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            DB::purge();
            SyncFeedEntry::query()->where('user_id', $user->id)->delete();
            Card::withTrashed()->where('deck_id', $deck->id)->forceDelete();
            Deck::withTrashed()->whereKey($deck->id)->forceDelete();
            User::query()->whereKey($user->id)->delete();
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
    private function sockets(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL new-card queue sockets.');
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function runReorderWorker($socket, int $userId, string $firstCardId, string $secondCardId): never
    {
        try {
            DB::setDefaultConnection('pgsql_reorder_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $userId, $firstCardId, $secondCardId): void {
                app(ReorderNewCardQueueAction::class)->handle(
                    $userId,
                    [$secondCardId, $firstCardId],
                );
                fwrite($socket, "reordered\n");
                fflush($socket);
                usleep(500_000);
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
}
