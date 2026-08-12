<?php

namespace Tests\Feature\Study;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ManualStudyCardOwnerLockPostgresTest extends TestCase
{
    public function test_manual_card_creation_waits_on_the_owner_before_account_deletion_reaches_the_deck(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create(['password' => 'correct-password123']);
        Deck::factory()->for($user)->create([
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);
        $deleteSockets = $this->sockets('account deletion');
        $createSockets = $this->sockets('manual-card creation');

        DB::disconnect();
        config()->set('database.connections.pgsql_delete_worker', config('database.connections.pgsql'));
        config()->set('database.connections.pgsql_create_worker', config('database.connections.pgsql'));
        $deletePid = pcntl_fork();

        if ($deletePid === -1) {
            throw new RuntimeException('Unable to fork the account-deletion worker.');
        }

        if ($deletePid === 0) {
            fclose($deleteSockets[0]);
            fclose($createSockets[0]);
            fclose($createSockets[1]);
            $this->runDeleteWorker($deleteSockets[1], $user->id);
        }

        fclose($deleteSockets[1]);
        stream_set_timeout($deleteSockets[0], 10);
        $deleteWorkerReady = trim((string) fgets($deleteSockets[0]));

        if ($deleteWorkerReady !== 'owner-locked') {
            pcntl_waitpid($deletePid, $deleteStatus);
            throw new RuntimeException('Account-deletion worker failed to acquire its owner lock: '.$deleteWorkerReady);
        }

        $createPid = pcntl_fork();

        if ($createPid === -1) {
            fwrite($deleteSockets[0], "delete\n");
            pcntl_waitpid($deletePid, $deleteStatus);
            throw new RuntimeException('Unable to fork the manual-card creation worker.');
        }

        if ($createPid === 0) {
            fclose($createSockets[0]);
            fclose($deleteSockets[0]);
            $this->runCreateWorker($createSockets[1], $user->id);
        }

        fclose($createSockets[1]);
        stream_set_timeout($createSockets[0], 10);

        try {
            $this->assertSame('starting', trim((string) fgets($createSockets[0])));
            usleep(150_000);
            fwrite($deleteSockets[0], "delete\n");
            fflush($deleteSockets[0]);

            pcntl_waitpid($deletePid, $deleteStatus);
            pcntl_waitpid($createPid, $createStatus);
            $deleteMessage = trim((string) fgets($deleteSockets[0]));
            $createMessage = trim((string) fgets($createSockets[0]));
            $this->assertSame(0, pcntl_wexitstatus($deleteStatus), 'delete: '.$deleteMessage);
            $this->assertSame('deleted', $deleteMessage);
            $this->assertSame(0, pcntl_wexitstatus($createStatus), 'create: '.$createMessage);
            $this->assertSame('owner-not-found', $createMessage);

            DB::purge();
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
            $this->assertSame(0, Deck::query()->where('user_id', $user->id)->count());
            $this->assertSame(0, Card::query()->count());
        } finally {
            fclose($deleteSockets[0]);
            fclose($createSockets[0]);

            if (isset($deleteStatus) === false) {
                fwrite($deleteSockets[0], "delete\n");
                fflush($deleteSockets[0]);
                pcntl_waitpid($deletePid, $deleteStatus);
            }

            if (isset($createStatus) === false) {
                pcntl_waitpid($createPid, $createStatus);
            }

            DB::purge();
            Card::query()->delete();
            Deck::query()->where('user_id', $user->id)->delete();
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
    private function sockets(string $label): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException("Unable to create PostgreSQL {$label} sockets.");
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function runDeleteWorker($socket, int $userId): never
    {
        try {
            DB::setDefaultConnection('pgsql_delete_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::beginTransaction();
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            fwrite($socket, "owner-locked\n");
            fflush($socket);

            if (trim((string) fgets($socket)) !== 'delete') {
                throw new RuntimeException('Account-deletion worker did not receive its release signal.');
            }

            app(DeleteCurrentUserAction::class)->handle($user, 'correct-password123');
            DB::commit();
            fwrite($socket, "deleted\n");
            fflush($socket);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            fwrite($socket, $exception::class.': '.$exception->getMessage()."\n");
            fflush($socket);
            fclose($socket);
            exit(1);
        }
    }

    /** @param resource $socket */
    private function runCreateWorker($socket, int $userId): never
    {
        try {
            DB::setDefaultConnection('pgsql_create_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            fwrite($socket, "starting\n");
            fflush($socket);

            try {
                DB::transaction(function () use ($userId): void {
                    $deck = app(ResolveManualStudyDeckAction::class)->handle($userId);
                    app(CreateCardAction::class)->handle(CreateCardData::fromInput(
                        userId: $userId,
                        deckId: $deck->id,
                        frontText: '犬',
                        backText: 'dog',
                        cardType: CardType::Recognition,
                        promptJson: ['cueText' => '犬'],
                        answerJson: ['meaning' => 'dog'],
                    ));
                });
                fwrite($socket, "unexpected-success\n");
                fclose($socket);
                exit(1);
            } catch (ModelNotFoundException) {
                fwrite($socket, "owner-not-found\n");
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
}
