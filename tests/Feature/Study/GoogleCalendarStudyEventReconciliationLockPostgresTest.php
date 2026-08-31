<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ReconcileGoogleCalendarStudyEventsAction;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class GoogleCalendarStudyEventReconciliationLockPostgresTest extends TestCase
{
    public function test_reconciliation_waits_for_a_concurrent_client_write_and_reuses_its_identity(): void
    {
        $this->requirePostgresConcurrency();
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $sourceKey = StudyActivitySourceKey::forGoogleCalendar('account', 'work', 'event');
        $this->mirror($connection, $sourceKey);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create calendar reconciliation worker sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the calendar reconciliation worker.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runClientWrite($sockets[1], $user->id, $sourceKey);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            $this->assertSame('upserted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            app(ReconcileGoogleCalendarStudyEventsAction::class)->handle($user->id, $connection);

            $this->assertGreaterThanOrEqual(350, (int) round((microtime(true) - $startedAt) * 1000));
            $session = StudyActivitySession::query()->sole();
            $this->assertSame('concurrent-client-id', $session->client_session_id);
            $this->assertSame('iTalki lesson', $session->name);
            $this->assertSame($sourceKey->value, $session->source_key);
            pcntl_waitpid($pid, $status);
            $message = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $message);
            $this->assertSame('committed', $message);
        } finally {
            fclose($sockets[0]);
            if (isset($status) === false) {
                pcntl_waitpid($pid, $status);
            }
            GoogleCalendarEventMirror::query()->where('google_calendar_connection_id', $connection->id)->delete();
            StudyActivitySession::query()->where('user_id', $user->id)->delete();
            $connection->delete();
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
    private function runClientWrite($socket, int $userId, StudyActivitySourceKey $sourceKey): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $userId, $sourceKey): void {
                app(UpsertStudyActivitySessionsAction::class)->handle($userId, [new StudyActivitySessionData(
                    clientSessionId: 'concurrent-client-id',
                    activity: StudyActivityKind::Conversation, source: StudyActivitySource::Calendar,
                    name: 'Concurrent client upload', startedAt: CarbonImmutable::parse('2026-08-15T08:00:00Z'),
                    endedAt: CarbonImmutable::parse('2026-08-15T09:00:00Z'), durationMs: 3_600_000,
                    audioPlaybackMs: null, cardsCreated: null, origin: StudyActivityOrigin::GoogleCalendar,
                    sourceKey: $sourceKey,
                )]);
                fwrite($socket, "upserted\n");
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

    private function connection(User $user): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id, 'provider_account_id' => 'account', 'account_email' => $user->email,
            'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'], 'settings' => [
                'calendarIds' => ['work'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => true,
            ], 'connected_at' => now(),
        ]);
    }

    private function mirror(GoogleCalendarConnection $connection, StudyActivitySourceKey $sourceKey): void
    {
        GoogleCalendarEventMirror::query()->forceCreate([
            'google_calendar_connection_id' => $connection->id, 'source_key' => $sourceKey->value,
            'calendar_id' => 'work', 'provider_event_id' => 'event', 'status' => 'confirmed',
            'title' => 'iTalki lesson', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
            'all_day' => false, 'observed_at' => now(),
        ]);
    }
}
