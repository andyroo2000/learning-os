<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CardUpdateDeletionLockPostgresTest extends TestCase
{
    public function test_update_waits_for_a_concurrent_delete_and_does_not_follow_its_tombstone(): void
    {
        $this->requirePostgresConcurrency();

        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '犬',
            'back_text' => 'dog',
        ]);
        $staleCard = Card::query()->findOrFail($card->id);
        $sockets = $this->sockets();

        DB::disconnect();
        config()->set('database.connections.pgsql_delete_worker', config('database.connections.pgsql'));
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the card-deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeleteWorker($sockets[1], $card->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            $updateStartedAt = microtime(true);

            try {
                app(UpdateCardAction::class)->handle(
                    $staleCard,
                    UpdateCardData::fromInput(frontText: '猫', backText: 'cat'),
                );
                $this->fail('Expected the stale update to reject the deleted card.');
            } catch (ModelNotFoundException) {
                // Delete remains the terminal operation for this card identity.
            }

            $this->assertGreaterThanOrEqual(
                0.35,
                microtime(true) - $updateStartedAt,
                'The update should wait for the deleting transaction to resolve.',
            );

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);

            $deletedCard = Card::withTrashed()->findOrFail($card->id);
            $this->assertTrue($deletedCard->trashed());
            $this->assertSame('犬', $deletedCard->front_text);
            $this->assertSame('dog', $deletedCard->back_text);
            $this->assertSame(
                [SyncFeedOperation::Delete],
                SyncFeedEntry::query()
                    ->where('user_id', $user->id)
                    ->where('resource_type', 'card')
                    ->orderBy('checkpoint')
                    ->pluck('operation')
                    ->all(),
            );
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            DB::purge();
            SyncFeedEntry::query()->where('user_id', $user->id)->delete();
            Card::withTrashed()->whereKey($card->id)->forceDelete();
            $user->delete();
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
            throw new RuntimeException('Unable to create PostgreSQL card update/delete sockets.');
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function runDeleteWorker($socket, string $cardId): never
    {
        try {
            DB::setDefaultConnection('pgsql_delete_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($cardId, $socket): void {
                $card = Card::query()->findOrFail($cardId);
                app(DeleteCardAction::class)->handle($card);
                fwrite($socket, "deleted\n");
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
