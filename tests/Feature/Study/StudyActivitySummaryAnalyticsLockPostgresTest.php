<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\BuildStudyActivityAnalyticsAction;
use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\StudyActivityAnalyticsOptions;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityAnalyticsRange;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class StudyActivitySummaryAnalyticsLockPostgresTest extends TestCase
{
    public function test_all_time_metadata_and_cached_totals_wait_for_an_earlier_session_to_commit(): void
    {
        $this->requirePostgresConcurrency();
        $user = User::factory()->create();
        try {
            $this->writeSession($user->id, '2026-08-15');
            $before = $this->allTime($user->id);
            $this->assertSame('2026-01-01T00:00:00.000000Z', $before['startsAt']);
            $this->assertSame(600_000, $before['totalMs']);
            $this->assertDatabaseHas('study_activity_daily_summaries', ['user_id' => $user->id]);

            $this->readDuringEarlierWrite($user->id);

            $this->assertSame(1_200_000, $this->allTime($user->id)['totalMs']);
        } finally {
            $user->delete();
        }
    }

    private function readDuringEarlierWrite(int $userId): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create analytics metadata worker sockets.');
        }
        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($sockets[0]);
            fclose($sockets[1]);
            throw new RuntimeException('Unable to fork the analytics metadata worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runEarlierWrite($sockets[1], $userId);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            $this->assertSame('held', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $started = microtime(true);
            $after = $this->allTime($userId);
            $this->assertGreaterThanOrEqual(350, (int) round((microtime(true) - $started) * 1000));
            // Reading MIN before the owner lock would retain 2026's start and omit the new credit.
            $this->assertSame('2025-01-01T00:00:00.000000Z', $after['startsAt']);
            $this->assertSame(1_200_000, $after['totalMs']);
            $this->assertSame(1_200_000, $after['categories']['review']);
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
    private function runEarlierWrite($socket, int $userId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $userId): void {
                $this->writeSession($userId, '2025-07-20');
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
            fflush($socket);
            fclose($socket);
            exit(1);
        }
    }

    private function writeSession(int $userId, string $date): void
    {
        app(UpsertStudyActivitySessionsAction::class)->handle($userId, [StudyActivitySessionData::fromValidated([
            'clientSessionId' => '018f22d2-6d38-7000-8000-'.str_replace('-', '', $date).'0001',
            'activity' => 'card_review', 'source' => 'manual', 'durationMs' => 600_000,
            'startedAt' => $date.'T12:00:00Z', 'endedAt' => $date.'T12:10:00Z',
        ])]);
    }

    private function allTime(int $userId): array
    {
        return app(BuildStudyActivityAnalyticsAction::class)->handle(
            $userId, new DateTimeZone('UTC'), 2, new StudyActivityAnalyticsOptions(
                now: CarbonImmutable::parse('2026-08-31T12:00:00Z'),
                ranges: [StudyActivityAnalyticsRange::All],
            ),
        )['ranges'][0];
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }
        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');
    }
}
