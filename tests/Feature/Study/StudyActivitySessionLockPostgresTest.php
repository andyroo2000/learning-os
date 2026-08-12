<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class StudyActivitySessionLockPostgresTest extends TestCase
{
    private const SOURCE_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_a_concurrent_first_write_cannot_downgrade_an_automatic_session(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $clientSessionId = '018f22d2-6d38-7000-8000-000000000023';
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL study activity worker sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL study activity worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runAutomaticUpsertWorker($sockets[1], $user->id, $clientSessionId);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('upserted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            $sessions = app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
                $this->sessionData(
                    $clientSessionId,
                    StudyActivitySource::Manual,
                    'Concurrent manual edit',
                ),
            ]);

            $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
            $this->assertGreaterThanOrEqual(
                350,
                $lockWaitMilliseconds,
                'Expected the second upsert to wait for the first source decision to commit.',
            );
            $this->assertSame(StudyActivitySource::Automatic, $sessions->sole()->source);
            $this->assertSame('Original automatic activity', $sessions->sole()->name);

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);
            $this->assertDatabaseCount('study_activity_sessions', 1);
            $this->assertDatabaseHas('study_activity_sessions', [
                'user_id' => $user->id,
                'client_session_id' => $clientSessionId,
                'source' => StudyActivitySource::Automatic->value,
                'name' => 'Original automatic activity',
            ]);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $status);
            }

            StudyActivitySession::query()->where('user_id', $user->id)->delete();
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

    /** @param resource $socket */
    private function runAutomaticUpsertWorker($socket, int $userId, string $clientSessionId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $userId, $clientSessionId): void {
                app(UpsertStudyActivitySessionsAction::class)->handle($userId, [
                    $this->sessionData(
                        $clientSessionId,
                        StudyActivitySource::Automatic,
                        'Original automatic activity',
                    ),
                ]);

                fwrite($socket, "upserted\n");
                fflush($socket);
                usleep(self::SOURCE_LOCK_HOLD_MICROSECONDS);
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

    private function sessionData(
        string $clientSessionId,
        StudyActivitySource $source,
        string $name,
    ): StudyActivitySessionData {
        return new StudyActivitySessionData(
            clientSessionId: $clientSessionId,
            category: StudyActivityCategory::Review,
            activity: StudyActivityKind::CardReview,
            source: $source,
            name: $name,
            startedAt: CarbonImmutable::parse('2026-07-28T12:00:00Z'),
            endedAt: CarbonImmutable::parse('2026-07-28T12:10:00Z'),
            durationMs: 600_000,
            audioPlaybackMs: null,
            cardsCreated: null,
        );
    }
}
