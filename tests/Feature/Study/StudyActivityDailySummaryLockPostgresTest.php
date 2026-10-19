<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\DeleteStudyActivitySessionAction;
use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Support\StudyActivityDailySummaries;
use App\Domain\Study\Support\StudyActivitySummaryWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class StudyActivityDailySummaryLockPostgresTest extends TestCase
{
    private const SESSION_ID = '018f22d2-6d38-7000-8000-000000000401';

    public function test_cold_fill_waits_for_the_first_writer_and_includes_its_committed_session(): void
    {
        $this->assertReadWaitsForWrite(null, 600_000);
    }

    public function test_a_warm_read_waits_for_a_writer_then_rebuilds_the_invalidated_day(): void
    {
        $this->assertReadWaitsForWrite(600_000, 300_000);
    }

    private function assertReadWaitsForWrite(?int $initialDurationMs, int $durationMs): void
    {
        $this->requirePostgresConcurrency();
        $user = User::factory()->create();
        try {
            if ($initialDurationMs !== null) {
                $this->writeSession($user->id, $initialDurationMs);
                $this->assertSame($initialDurationMs, $this->reviewTotal($user->id));
            }
            $this->withLockedWorker(
                fn () => $this->writeSession($user->id, $durationMs),
                function () use ($user, $durationMs): void {
                    $started = microtime(true);
                    $total = $this->reviewTotal($user->id);
                    $this->assertWaitedForOwnerLock($started);
                    $this->assertSame($durationMs, $total);
                },
            );
            $this->assertSame($durationMs, $this->reviewTotal($user->id));
        } finally {
            $user->delete();
        }
    }

    public function test_delete_waits_for_a_cold_fill_and_invalidates_the_just_persisted_summary(): void
    {
        $this->requirePostgresConcurrency();
        $user = User::factory()->create();
        try {
            $this->writeSession($user->id, 600_000);
            $this->withLockedWorker(
                fn () => $this->reviewTotal($user->id),
                function () use ($user): void {
                    $started = microtime(true);
                    $deleted = app(DeleteStudyActivitySessionAction::class)->handle($user->id, self::SESSION_ID);
                    $this->assertWaitedForOwnerLock($started);
                    $this->assertTrue($deleted);
                },
            );
            $this->assertDatabaseMissing('study_activity_sessions', ['user_id' => $user->id]);
            $this->assertDatabaseMissing('study_activity_daily_summaries', ['user_id' => $user->id]);
            $this->assertSame(0, $this->reviewTotal($user->id));
        } finally {
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

    private function withLockedWorker(Closure $worker, Closure $whileLocked): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create daily-summary worker sockets.');
        }
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            throw new RuntimeException('Unable to fork the daily-summary worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runWorker($sockets[1], $worker);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            $this->assertSame('held', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $whileLocked();
            pcntl_waitpid($pid, $status);
            $message = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $message);
            $this->assertSame('committed', $message);
        } finally {
            fclose($sockets[0]);
            if (! isset($status)) {
                pcntl_waitpid($pid, $status);
            }
        }
    }

    /** @param resource $socket */
    private function runWorker($socket, Closure $operation): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $operation): void {
                $operation();
                fwrite($socket, "held\n");
                fflush($socket);
                usleep(500_000);
            });
            fwrite($socket, "committed\n");
            fflush($socket);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, $exception::class.': '.$exception->getMessage()."\n");
            fclose($socket);
            exit(1);
        }
    }

    private function assertWaitedForOwnerLock(float $started): void
    {
        $this->assertGreaterThanOrEqual(350, (int) round((microtime(true) - $started) * 1000));
    }

    private function writeSession(int $userId, int $durationMs): void
    {
        app(UpsertStudyActivitySessionsAction::class)->handle($userId, [StudyActivitySessionData::fromValidated([
            'clientSessionId' => self::SESSION_ID, 'activity' => 'card_review', 'source' => 'manual',
            'startedAt' => '2026-08-15T12:00:00Z', 'endedAt' => '2026-08-15T12:10:00Z',
            'durationMs' => $durationMs,
        ])]);
    }

    private function reviewTotal(int $userId): int
    {
        $days = app(StudyActivityDailySummaries::class)->forWindow($userId, new StudyActivitySummaryWindow(
            new DateTimeZone('UTC'), CarbonImmutable::parse('2026-08-15T00:00:00Z'),
            CarbonImmutable::parse('2026-08-16T00:00:00Z'), CarbonImmutable::parse('2026-08-31T12:00:00Z'),
        ));

        return $days[0]['categories']['review'];
    }
}
