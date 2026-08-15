<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ClaimGoogleCalendarConnectIntentAction;
use App\Domain\Calendar\Models\GoogleCalendarConnectIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class GoogleCalendarConnectIntentLockPostgresTest extends TestCase
{
    public function test_concurrent_callbacks_can_claim_an_intent_only_once(): void
    {
        $this->requirePostgresConcurrency();
        $user = User::factory()->create();
        $state = bin2hex(random_bytes(32));
        (new GoogleCalendarConnectIntent)->forceFill([
            'state_hash' => hash('sha256', $state),
            'user_id' => $user->id,
            'completion_target' => 'ios',
            'expires_at' => now()->addMinutes(10),
        ])->save();

        /** @var list<array{pid: int, socket: resource}> $workers */
        $workers = [];
        $waitedPids = [];
        DB::disconnect();

        try {
            for ($index = 0; $index < 2; $index++) {
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($sockets === false) {
                    throw new RuntimeException('Unable to create PostgreSQL Calendar intent worker sockets.');
                }

                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork the PostgreSQL Calendar intent worker.');
                }
                if ($pid === 0) {
                    fclose($sockets[0]);
                    $this->runClaimWorker($sockets[1], $state);
                }

                fclose($sockets[1]);
                stream_set_timeout($sockets[0], 10);
                $workers[] = ['pid' => $pid, 'socket' => $sockets[0]];
            }

            foreach ($workers as $worker) {
                fwrite($worker['socket'], "claim\n");
                fflush($worker['socket']);
            }

            $results = [];
            foreach ($workers as $worker) {
                $results[] = trim((string) fgets($worker['socket']));
                pcntl_waitpid($worker['pid'], $status);
                $waitedPids[] = $worker['pid'];
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status), end($results));
            }

            sort($results);
            $this->assertSame(['claimed', 'miss'], $results);
            DB::purge();
            $this->assertDatabaseMissing('google_calendar_connect_intents', [
                'state_hash' => hash('sha256', $state),
            ]);
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                if (! in_array($worker['pid'], $waitedPids, true)) {
                    pcntl_waitpid($worker['pid'], $status);
                }
            }
            DB::purge();
            GoogleCalendarConnectIntent::query()->where('user_id', $user->id)->delete();
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
    private function runClaimWorker($socket, string $state): never
    {
        try {
            fgets($socket);
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            $claim = app(ClaimGoogleCalendarConnectIntentAction::class)->handle($state);
            fwrite($socket, $claim === null ? "miss\n" : "claimed\n");
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
