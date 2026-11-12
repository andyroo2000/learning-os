<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\DeleteJournalEntry;
use App\Domain\Journal\Actions\SaveJournalDraft;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class JournalDraftLockPostgresTest extends TestCase
{
    #[DataProvider('versions')]
    public function test_concurrent_writes_preserve_the_first_committed_text(int $version, bool $delete): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for runtime locks.');
        }
        $this->assertTrue(function_exists('pcntl_fork'));
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        if ($version === 1) {
            JournalEntry::factory()->for($user)->create(['id' => $id]);
        }
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->writeInWorker($sockets[1], $user->id, $id, $version);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        try {
            $this->assertSame("locked\n", fgets($sockets[0]));
            $this->assertConflictingWrite($user->id, $id, $version, $delete);
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), (string) stream_get_contents($sockets[0]));
            $entry = JournalEntry::findOrFail($id);
            $this->assertSame('先に保存した文章。', $entry->body);
            $this->assertSame($version + 1, $entry->version);
        } finally {
            if (! isset($status)) {
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            User::whereKey($user->id)->delete();
        }
    }

    public static function versions(): array
    {
        return ['first create' => [0, false], 'existing draft' => [1, false], 'stale deletion' => [1, true]];
    }

    private function assertConflictingWrite(int $userId, string $id, int $version, bool $delete): void
    {
        DB::purge();
        DB::statement("SET lock_timeout = '5s'");
        try {
            if ($delete) {
                app(DeleteJournalEntry::class)->handle($userId, $id, $version);
            } else {
                app(SaveJournalDraft::class)->handle($userId, $id, $this->draft($version, '別の端末の文章。'));
            }
            $this->fail('A stale writer must not overwrite the committed entry.');
        } catch (JournalVersionConflict) {
            $this->assertTrue(true);
        }
    }

    /** @param resource $socket */
    private function writeInWorker($socket, int $userId, string $id, int $version): never
    {
        try {
            DB::purge();
            DB::statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $userId, $id, $version): void {
                app(SaveJournalDraft::class)->handle($userId, $id, $this->draft($version, '先に保存した文章。'));
                fwrite($socket, "locked\n");
                fflush($socket);
                usleep(300_000);
            });
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($socket, $exception->getMessage());
            fclose($socket);
            exit(1);
        }
    }

    private function draft(int $version, string $body): array
    {
        return ['title' => null, 'body' => $body, 'entryDate' => '2026-11-12', 'expectedVersion' => $version];
    }
}
