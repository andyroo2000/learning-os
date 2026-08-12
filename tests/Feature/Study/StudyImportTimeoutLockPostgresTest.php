<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\PrepareStudyImportActiveSlotAction;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;
use Throwable;

class StudyImportTimeoutLockPostgresTest extends TestCase
{
    use BuildsStudyImportArchives;

    private const COMPLETION_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_timeout_waits_for_import_completion_then_rechecks_processing_state(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise runtime row-lock behavior.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $startedAt = Carbon::parse('2026-08-12 06:00:00');
        $timeoutAt = $startedAt->copy()->addMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1);
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->uploadCompleted()->for($user)->create([
            'source_object_path' => null,
        ]);
        $studyImportsRoot = storage_path('framework/testing/disks/study-import-timeout-'.$importJob->id);
        $mediaRoot = storage_path('framework/testing/disks/study-import-timeout-media-'.$importJob->id);
        Config::set('filesystems.disks.study-imports', [
            'driver' => 'local',
            'root' => $studyImportsRoot,
            'throw' => false,
        ]);
        Config::set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => $mediaRoot,
            'throw' => false,
        ]);
        Storage::forgetDisk('study-imports');
        Storage::forgetDisk('media');
        $sourceObjectPath = StudyImportUploadPath::forImportJob(
            $user->id,
            $importJob->id,
            'concurrent-timeout.colpkg',
        );
        $importJob->source_object_path = $sourceObjectPath;
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL import-timeout worker sockets.');
        }

        StudyImportJob::updating(function (StudyImportJob $saving) use ($sockets): void {
            if ($saving->status !== StudyImportStatus::Completed || ! $saving->isDirty('status')) {
                return;
            }

            fwrite($sockets[1], "locked\n");
            fflush($sockets[1]);
            usleep(self::COMPLETION_LOCK_HOLD_MICROSECONDS);
        });
        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the PostgreSQL import worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runImportWorker($sockets[1], $importJob->id, $startedAt);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('locked', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $waitStartedAt = microtime(true);

            $activeImport = DB::transaction(
                fn (): ?StudyImportJob => app(PrepareStudyImportActiveSlotAction::class)
                    ->handle($user->id, $timeoutAt),
            );
            $lockWaitMilliseconds = (int) round((microtime(true) - $waitStartedAt) * 1000);

            $this->assertNull($activeImport);
            $this->assertGreaterThanOrEqual(
                350,
                $lockWaitMilliseconds,
                'Expected timeout preparation to wait for the importer owner lock before rechecking state.',
            );

            pcntl_waitpid($pid, $status);
            $workerMessage = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $workerMessage);
            $this->assertSame('completed', $workerMessage);
            $this->assertSame(StudyImportStatus::Completed, $importJob->refresh()->status);
            $this->assertNull($importJob->error_message);
            $this->assertSame($startedAt->toJSON(), $importJob->completed_at?->toJSON());
            $this->assertSame(1, Deck::query()->where('user_id', $user->id)->count());
            $this->assertFalse(Storage::disk('study-imports')->exists($sourceObjectPath));
        } finally {
            fclose($sockets[0]);

            if (! isset($status)) {
                pcntl_waitpid($pid, $status);
            }

            User::query()->whereKey($user->id)->delete();
            Storage::forgetDisk('study-imports');
            Storage::forgetDisk('media');
            File::deleteDirectory($studyImportsRoot);
            File::deleteDirectory($mediaRoot);
        }
    }

    /** @param resource $socket */
    private function runImportWorker($socket, string $importJobId, Carbon $startedAt): never
    {
        try {
            DB::purge();
            DB::connection()->statement("SET statement_timeout = '15s'");
            $processed = app(ProcessStudyImportJobAction::class)->handle($importJobId, $startedAt);

            if ($processed?->status !== StudyImportStatus::Completed) {
                throw new RuntimeException('Import worker did not complete the claimed import.');
            }

            fwrite($socket, "completed\n");
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
