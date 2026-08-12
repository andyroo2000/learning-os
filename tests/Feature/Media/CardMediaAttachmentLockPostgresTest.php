<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CardMediaAttachmentLockPostgresTest extends TestCase
{
    private const DELETION_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_attachment_waits_for_a_concurrent_card_deletion_and_then_rejects_the_stale_card(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $staleCard = Card::query()->findOrFail($card->id);
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL card deletion sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL card deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], $card->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            try {
                app(AttachMediaToCardAction::class)->handle(
                    AttachMediaToCardData::fromModels($staleCard, $mediaAsset),
                );

                $this->fail('Expected the concurrently deleted card to be rejected.');
            } catch (ModelNotFoundException) {
                $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
                $this->assertGreaterThanOrEqual(
                    350,
                    $lockWaitMilliseconds,
                    'Expected attachment to wait for the card deletion before checking liveness.',
                );
            }

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);
            $this->assertSoftDeleted('cards', ['id' => $card->id]);
            $this->assertDatabaseMissing('card_media', [
                'card_id' => $card->id,
                'media_asset_id' => $mediaAsset->id,
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 1);
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => 'card',
                'resource_id' => $card->id,
                'operation' => SyncFeedOperation::Delete->value,
            ]);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $status);
            }

            SyncFeedEntry::query()->where('user_id', $user->id)->delete();
            MediaAsset::query()->whereKey($mediaAsset->id)->delete();
            Card::query()->withTrashed()->whereKey($card->id)->forceDelete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    public function test_attachment_commits_before_a_concurrent_parent_deck_deletion_tombstones_the_card(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $deck = $card->deck()->firstOrFail();
        $staleCard = Card::query()->findOrFail($card->id);
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $workerName = 'card_media_deck_delete_'.bin2hex(random_bytes(6));
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL deck deletion sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL deck deletion worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeckDeletionWorker($sockets[1], $deck->id, $workerName);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        $transactionOpen = false;

        try {
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            DB::beginTransaction();
            $transactionOpen = true;

            Card::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            fwrite($sockets[0], "delete\n");
            fflush($sockets[0]);

            $this->assertSame('deleting', trim((string) fgets($sockets[0])));
            $this->waitForPostgresWorkerLock($workerName);

            app(AttachMediaToCardAction::class)->handle(
                AttachMediaToCardData::fromModels($staleCard, $mediaAsset),
            );

            DB::commit();
            $transactionOpen = false;

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);
            $this->assertSoftDeleted('decks', ['id' => $deck->id]);
            $this->assertSoftDeleted('cards', ['id' => $card->id]);
            $this->assertDatabaseHas('card_media', [
                'card_id' => $card->id,
                'media_asset_id' => $mediaAsset->id,
            ]);

            $entries = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->orderBy('checkpoint')
                ->get();

            $this->assertCount(3, $entries);
            $this->assertSame('card_media', $entries[0]->resource_type);
            $this->assertSame(SyncFeedOperation::Create, $entries[0]->operation);
            $this->assertSame('card', $entries[1]->resource_type);
            $this->assertSame(SyncFeedOperation::Delete, $entries[1]->operation);
            $this->assertSame('deck', $entries[2]->resource_type);
            $this->assertSame(SyncFeedOperation::Delete, $entries[2]->operation);
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }

            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $status);
            }

            SyncFeedEntry::query()->where('user_id', $user->id)->delete();
            MediaAsset::query()->whereKey($mediaAsset->id)->delete();
            Deck::query()->withTrashed()->whereKey($deck->id)->forceDelete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    /** @param resource $socket */
    private function runDeletionWorker($socket, string $cardId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $cardId): void {
                $card = Card::query()->findOrFail($cardId);

                app(DeleteCardAction::class)->handle($card);

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

    /** @param resource $socket */
    private function runDeckDeletionWorker($socket, string $deckId, string $workerName): never
    {
        try {
            if (trim((string) fgets($socket)) !== 'delete') {
                throw new RuntimeException('Deck deletion worker did not receive its start signal.');
            }

            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::selectOne("select set_config('application_name', ?, false)", [$workerName]);
            fwrite($socket, "deleting\n");
            fflush($socket);

            $deck = Deck::query()->findOrFail($deckId);
            app(DeleteDeckAction::class)->handle($deck);

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

    private function waitForPostgresWorkerLock(string $workerName): void
    {
        $deadline = microtime(true) + 5;

        do {
            $waitingWorkers = DB::table('pg_stat_activity')
                ->where('application_name', $workerName)
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($waitingWorkers === 1) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Deck deletion worker did not block on the card row lock.');
    }
}
