<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class GoogleCalendarManualSyncLockPostgresTest extends TestCase
{
    public function test_repeated_manual_requests_serialize_to_one_run(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }
        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $connection = GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id,
            'provider_account_id' => 'manual-sync-lock-'.$user->id,
            'account_email' => $user->email,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'],
            'settings' => ['calendarIds' => ['work'], 'titleMatchTerms' => ['lesson'], 'syncEnabled' => false],
            'connected_at' => now(),
        ]);
        DB::unprepared('CREATE FUNCTION calendar_manual_sync_lock_delay() RETURNS trigger AS $$ BEGIN PERFORM pg_sleep(0.4); RETURN NEW; END; $$ LANGUAGE plpgsql');
        DB::unprepared('CREATE TRIGGER calendar_manual_sync_lock_delay BEFORE UPDATE ON google_calendar_connections FOR EACH ROW EXECUTE FUNCTION calendar_manual_sync_lock_delay()');
        $workers = [$this->sockets(), $this->sockets()];

        DB::disconnect();
        $pids = [];
        foreach ($workers as $index => $sockets) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a Google Calendar sync worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $this->runWorker($sockets[1], $user->id);
            }
            $pids[$index] = $pid;
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 10);
        }

        try {
            foreach ($workers as $sockets) {
                $this->assertSame('starting', trim((string) fgets($sockets[0])));
            }
            foreach ($workers as $sockets) {
                fwrite($sockets[0], "go\n");
                fflush($sockets[0]);
            }

            $results = [];
            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $message = trim((string) fgets($workers[$index][0]));
                $this->assertSame(0, pcntl_wexitstatus($status), $message);
                $results[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame([202, 202], array_column($results, 'status'));
            $this->assertCount(1, array_unique(array_column($results, 'run')));
            $this->assertSame(1, array_sum(array_column($results, 'jobs')));
            DB::purge();
            $fresh = GoogleCalendarConnection::query()->findOrFail($connection->id);
            $this->assertSame('queued', $fresh->sync_status->value);
            $this->assertSame($results[0]['run'], $fresh->sync_run_id);
        } finally {
            foreach ($workers as $sockets) {
                fclose($sockets[0]);
            }
            DB::purge();
            DB::unprepared('DROP TRIGGER IF EXISTS calendar_manual_sync_lock_delay ON google_calendar_connections');
            DB::unprepared('DROP FUNCTION IF EXISTS calendar_manual_sync_lock_delay()');
            GoogleCalendarConnection::query()->whereKey($connection->id)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    /** @return array{0: resource, 1: resource} */
    private function sockets(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create Google Calendar sync sockets.');
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function runWorker($socket, int $userId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            Queue::fake();
            fwrite($socket, "starting\n");
            fflush($socket);
            if (trim((string) fgets($socket)) !== 'go') {
                throw new RuntimeException('Google Calendar sync worker did not receive its start signal.');
            }

            $user = User::query()->findOrFail($userId);
            $response = $this->actingAs($user)->postJson('/api/study/google-calendar/sync');
            $run = GoogleCalendarConnection::query()->where('user_id', $userId)->value('sync_run_id');
            $jobs = Queue::pushed(SyncGoogleCalendarConnection::class)->count();
            fwrite($socket, json_encode(['status' => $response->status(), 'run' => $run, 'jobs' => $jobs], JSON_THROW_ON_ERROR)."\n");
            fclose($socket);
            exit($response->status() === 202 ? 0 : 1);
        } catch (Throwable $e) {
            fwrite($socket, $e::class.': '.$e->getMessage()."\n");
            fclose($socket);
            exit(1);
        }
    }
}
