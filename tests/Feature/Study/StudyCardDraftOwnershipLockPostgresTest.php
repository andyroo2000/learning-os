<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Actions\CreateStudyCardFromDraftAction;
use App\Domain\Study\Actions\DeleteStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class StudyCardDraftOwnershipLockPostgresTest extends TestCase
{
    private const OWNER_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_delete_waits_for_a_concurrent_first_create_and_removes_the_committed_draft(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $draftId = strtolower((string) str()->ulid());
        $sockets = $this->sockets('draft create/delete');

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL draft creation worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runCreateWorker($sockets[1], $user->id, $draftId);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('created', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            app(DeleteStudyCardDraftAction::class)->handle($user->id, $draftId);

            $this->assertWaitedForLock(
                $startedAt,
                'Expected draft deletion to wait for the first create to commit.',
            );
            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);
            $this->assertDatabaseMissing('study_card_drafts', ['id' => $draftId]);

            $entries = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->where('resource_type', 'study_card_draft')
                ->orderBy('checkpoint')
                ->get();

            $this->assertCount(2, $entries);
            $this->assertSame(
                [SyncFeedOperation::Create, SyncFeedOperation::Delete],
                $entries->pluck('operation')->all(),
            );
            $this->assertSame([$draftId, $draftId], $entries->pluck('resource_id')->all());
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
        }
    }

    public function test_create_from_draft_waits_on_the_owner_before_delete_locks_the_draft(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '犬'],
            'answer_json' => ['meaning' => 'dog'],
        ]);
        $cardId = strtolower((string) str()->ulid());
        $sockets = $this->sockets('draft commit/delete');

        DB::disconnect();
        config()->set('database.connections.pgsql_worker', config('database.connections.pgsql'));
        DB::beginTransaction();
        DB::table('users')->where('id', $user->id)->lockForUpdate()->value('id');
        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::rollBack();
            throw new RuntimeException('Unable to fork the PostgreSQL draft commit worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runCreateFromDraftWorker($sockets[1], $user->id, $draft->id, $cardId);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('starting', trim((string) fgets($sockets[0])));
            usleep(150_000);
            $startedAt = microtime(true);

            app(DeleteStudyCardDraftAction::class)->handle($user->id, $draft->id);

            $deleteMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
            $this->assertLessThan(
                300,
                $deleteMilliseconds,
                'Delete should not wait on a draft lock held before the shared owner lock.',
            );
            DB::commit();

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('not-found', $workerMessage);
            $this->assertDatabaseMissing('study_card_drafts', ['id' => $draft->id]);
            $this->assertSame(
                0,
                Card::query()->whereHas('deck', fn ($query) => $query->where('user_id', $user->id))->count(),
            );
            $this->assertSame(0, Deck::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, SyncFeedEntry::query()->where('user_id', $user->id)->count());
            $this->assertDatabaseHas('sync_feed_entries', [
                'user_id' => $user->id,
                'resource_type' => 'study_card_draft',
                'resource_id' => $draft->id,
                'operation' => SyncFeedOperation::Delete->value,
            ]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

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
    private function runCreateWorker($socket, int $userId, string $draftId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $userId, $draftId): void {
                app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
                    userId: $userId,
                    creationKind: StudyCardCreationKind::TextRecognition,
                    cardType: CardType::Recognition,
                    promptJson: ['cueText' => '犬'],
                    answerJson: ['meaning' => 'dog'],
                    id: $draftId,
                ));

                fwrite($socket, "created\n");
                fflush($socket);
                usleep(self::OWNER_LOCK_HOLD_MICROSECONDS);
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
    private function runCreateFromDraftWorker(
        $socket,
        int $userId,
        string $draftId,
        string $cardId,
    ): never {
        try {
            DB::setDefaultConnection('pgsql_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            fwrite($socket, "starting\n");
            fflush($socket);

            try {
                app(CreateStudyCardFromDraftAction::class)->handle($userId, $draftId, $cardId);
                fwrite($socket, "unexpected-success\n");
                fflush($socket);
                fclose($socket);
                exit(1);
            } catch (StudyCardDraftNotFoundException) {
                fwrite($socket, "not-found\n");
                fflush($socket);
                fclose($socket);
                exit(0);
            }
        } catch (Throwable $exception) {
            fwrite($socket, $exception::class.': '.$exception->getMessage()."\n");
            fflush($socket);
            fclose($socket);
            exit(1);
        }
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
        Card::query()->whereHas('deck', fn ($query) => $query->where('user_id', $user->id))->delete();
        Deck::query()->where('user_id', $user->id)->delete();
        StudyCardDraft::query()->where('user_id', $user->id)->delete();
        User::query()->whereKey($user->id)->delete();
    }
}
