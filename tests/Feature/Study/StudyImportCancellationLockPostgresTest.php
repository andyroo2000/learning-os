<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CancelStudyImportUploadAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class StudyImportCancellationLockPostgresTest extends TestCase
{
    private const PROCESSING_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_a_late_cancellation_rechecks_state_after_the_processing_lock_commits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create(['source_object_path' => null]);
        $sourceObjectPath = StudyImportUploadPath::forImportJob(
            $user->id,
            $importJob->id,
            'concurrent-cancel.colpkg',
        );
        $importJob->source_object_path = $sourceObjectPath;
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK archive');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL cancellation worker sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL processing worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runProcessingWorker($sockets[1], $importJob->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('locked', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            try {
                app(CancelStudyImportUploadAction::class)->handle($user->id, $importJob->id);
                $this->fail('Expected a late cancellation to reject the committed Processing state.');
            } catch (StudyImportConflictException $exception) {
                $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
                $this->assertSame('study_import_processing', $exception->reason());
                $this->assertGreaterThanOrEqual(
                    350,
                    $lockWaitMilliseconds,
                    'Expected cancellation to wait for the processing row lock before rechecking state.',
                );
            }

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('committed', $workerMessage);
            $this->assertSame(StudyImportStatus::Processing, $importJob->refresh()->status);
            $this->assertNull($importJob->error_message);
            $this->assertNull($importJob->completed_at);
            Storage::disk('study-imports')->assertExists($sourceObjectPath);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $status);
            }

            StudyImportJob::query()->whereKey($importJob->id)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    /** @param resource $socket */
    private function runProcessingWorker($socket, string $importJobId): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $importJobId): void {
                $importJob = StudyImportJob::query()->whereKey($importJobId)->lockForUpdate()->firstOrFail();
                $importJob->status = StudyImportStatus::Processing;
                $importJob->started_at = now();
                $importJob->saveOrFail();
                fwrite($socket, "locked\n");
                fflush($socket);
                usleep(self::PROCESSING_LOCK_HOLD_MICROSECONDS);
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
}
