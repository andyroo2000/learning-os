<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class MediaAssetDeletionLockPostgresTest extends TestCase
{
    private const ATTACHMENT_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_deletion_waits_for_a_concurrent_attachment_before_snapshotting_tombstones(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL media attachment sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL media attachment worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runAttachmentWorker($sockets[1], $card->id, $mediaAsset->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('attached', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            app(DeleteMediaAssetAction::class)->handle(DeleteMediaAssetData::fromInput(
                userId: $user->id,
                mediaAssetId: $mediaAsset->id,
            ));

            $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
            $this->assertGreaterThanOrEqual(
                350,
                $lockWaitMilliseconds,
                'Expected deletion to wait for the attachment transaction before reading pivots.',
            );

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);
            $this->assertDatabaseMissing('media_assets', ['id' => $mediaAsset->id]);
            $this->assertDatabaseMissing('card_media', [
                'card_id' => $card->id,
                'media_asset_id' => $mediaAsset->id,
            ]);
            $this->assertDatabaseHas('cards', ['id' => $card->id]);
            $this->assertDatabaseCount('sync_feed_entries', 3);
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => CardMediaSyncPayload::RESOURCE_TYPE,
                'resource_id' => CardMediaSyncPayload::resourceId($card->id, $mediaAsset->id),
                'operation' => SyncFeedOperation::Create->value,
            ]);
            $this->assertDatabaseHas('sync_feed_entries', [
                'resource_type' => CardMediaSyncPayload::RESOURCE_TYPE,
                'resource_id' => CardMediaSyncPayload::resourceId($card->id, $mediaAsset->id),
                'operation' => SyncFeedOperation::Delete->value,
            ]);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $status);
            }

            SyncFeedEntry::query()->where('user_id', $user->id)->delete();
            MediaAsset::query()->whereKey($mediaAsset->id)->delete();
            Card::query()->whereKey($card->id)->forceDelete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    /** @param resource $socket */
    private function runAttachmentWorker($socket, string $cardId, string $mediaAssetId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $cardId, $mediaAssetId): void {
                $card = Card::query()->findOrFail($cardId);
                $mediaAsset = MediaAsset::query()->findOrFail($mediaAssetId);

                app(AttachMediaToCardAction::class)->handle(AttachMediaToCardData::fromModels(
                    card: $card,
                    mediaAsset: $mediaAsset,
                ));

                fwrite($socket, "attached\n");
                fflush($socket);
                usleep(self::ATTACHMENT_LOCK_HOLD_MICROSECONDS);
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
