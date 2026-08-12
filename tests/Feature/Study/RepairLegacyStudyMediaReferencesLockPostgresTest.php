<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Actions\DeleteCardAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\RepairLegacyStudyMediaReferencesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class RepairLegacyStudyMediaReferencesLockPostgresTest extends TestCase
{
    public function test_repair_waits_for_concurrent_deletion_and_does_not_follow_the_tombstone(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'prompt_json' => [
                'cueAudio' => [
                    'id' => '7ff08851-1396-4960-8cfe-cb3c348092ce',
                    'filename' => 'word.mp3',
                    'url' => '/api/study/media/7ff08851-1396-4960-8cfe-cb3c348092ce',
                    'mediaKind' => 'audio',
                    'source' => 'imported',
                ],
            ],
        ]);
        $media = MediaAsset::factory()->for($user)->create([
            'path' => 'study-media/user/word.mp3',
            'mime_type' => 'audio/mpeg',
            'original_filename' => 'word.mp3',
            'source_filename' => 'word.mp3',
        ]);
        $card->mediaAssets()->attach($media);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL media-repair/delete sockets.');
        }

        DB::disconnect();
        config()->set('database.connections.pgsql_media_repair_worker', config('database.connections.pgsql'));
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the media-repair deletion worker.');
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
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            $result = app(RepairLegacyStudyMediaReferencesAction::class)->handle(
                DB::connection(),
                apply: true,
                cardIds: [$card->id],
            );

            $this->assertGreaterThanOrEqual(
                350,
                (int) round((microtime(true) - $startedAt) * 1000),
                'Expected repair to wait for the deleting transaction.',
            );
            $this->assertSame(0, $result->cardsScanned);
            $this->assertSame(0, $result->cardsChanged);
            $this->assertSame(0, $result->referencesChanged);

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);

            $deletedCard = Card::query()->withTrashed()->findOrFail($card->id);
            $this->assertTrue($deletedCard->trashed());
            $this->assertSame('7ff08851-1396-4960-8cfe-cb3c348092ce', $deletedCard->prompt_json['cueAudio']['id']);
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

            if (! isset($status)) {
                pcntl_waitpid($pid, $workerStatus);
            }

            DB::purge();
            SyncFeedEntry::query()->where('user_id', $user->id)->delete();
            DB::table('card_media')->where('card_id', $card->id)->delete();
            MediaAsset::query()->whereKey($media->id)->delete();
            Card::query()->withTrashed()->whereKey($card->id)->forceDelete();
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

    /** @param resource $socket */
    private function runDeleteWorker($socket, string $cardId): never
    {
        try {
            DB::setDefaultConnection('pgsql_media_repair_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $cardId): void {
                app(DeleteCardAction::class)->handle(Card::query()->findOrFail($cardId));
                fwrite($socket, "deleted\n");
                fflush($socket);
                usleep(500_000);
            });
            fwrite($socket, "committed\n");
            fflush($socket);
            fclose($socket);
            exit(0);
        } catch (Throwable $e) {
            fwrite($socket, $e::class.': '.$e->getMessage()."\n");
            fflush($socket);
            fclose($socket);
            exit(1);
        }
    }
}
