<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Actions\DeleteCourseAction;
use App\Domain\Courses\Actions\UpdateCourseAction;
use App\Domain\Courses\Data\UpdateCourseData;
use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Actions\UpdateDeckAction;
use App\Domain\Flashcards\Data\UpdateDeckData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class HierarchyUpdateDeletionLockPostgresTest extends TestCase
{
    private const DELETION_LOCK_HOLD_MICROSECONDS = 500_000;

    public function test_deck_update_waits_for_a_concurrent_delete_and_does_not_follow_its_tombstone(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create([
            'name' => 'Original deck',
            'description' => 'Original description.',
        ]);

        $this->assertStaleUpdateRejectsDeletedResource('deck', $user, $deck);
    }

    public function test_course_update_waits_for_a_concurrent_delete_and_does_not_follow_its_tombstone(): void
    {
        $this->requirePostgresConcurrency();

        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create([
            'title' => 'Original course',
            'description' => 'Original description.',
        ]);

        $this->assertStaleUpdateRejectsDeletedResource('course', $user, $course);
    }

    private function assertStaleUpdateRejectsDeletedResource(
        string $resourceType,
        User $user,
        Deck|Course $model,
    ): void {
        $staleModel = $model::query()->findOrFail($model->getKey());
        $sockets = $this->sockets($resourceType);

        DB::disconnect();
        config()->set('database.connections.pgsql_delete_worker', config('database.connections.pgsql'));
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Unable to fork the PostgreSQL {$resourceType} deletion worker.");
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runDeletionWorker($sockets[1], $resourceType, (string) $model->getKey());
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);

        try {
            $this->assertSame('deleted', trim((string) fgets($sockets[0])));
            DB::purge();
            DB::connection()->statement("SET lock_timeout = '5s'");
            $startedAt = microtime(true);

            try {
                $this->performUpdate($resourceType, $staleModel);
                $this->fail("Expected the stale {$resourceType} update to reject the deleted resource.");
            } catch (ModelNotFoundException) {
                // Delete remains the terminal operation for this resource identity.
            }

            $this->assertWaitedForLock(
                $startedAt,
                "Expected {$resourceType} update to wait for concurrent deletion.",
            );
            $status = $this->waitForWorker($pid, $sockets[0]);
            $this->assertSame(0, $status);

            $deletedModel = $model::query()->withTrashed()->findOrFail($model->getKey());
            $this->assertTrue($deletedModel->trashed());

            if ($deletedModel instanceof Deck) {
                $this->assertSame('Original deck', $deletedModel->name);
            } else {
                $this->assertSame('Original course', $deletedModel->title);
            }

            $entries = SyncFeedEntry::query()
                ->where('user_id', $user->id)
                ->where('resource_type', $resourceType)
                ->where('resource_id', $model->getKey())
                ->orderBy('checkpoint')
                ->get();

            $this->assertCount(1, $entries);
            $this->assertSame(SyncFeedOperation::Delete, $entries->sole()->operation);
        } finally {
            fclose($sockets[0]);

            if (isset($status) === false) {
                pcntl_waitpid($pid, $workerStatus);
            }

            $this->cleanup($user);
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
    private function sockets(string $resourceType): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException("Unable to create PostgreSQL {$resourceType} update/delete sockets.");
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function runDeletionWorker($socket, string $resourceType, string $resourceId): never
    {
        try {
            DB::setDefaultConnection('pgsql_delete_worker');
            DB::connection()->statement("SET statement_timeout = '10s'");
            DB::transaction(function () use ($socket, $resourceType, $resourceId): void {
                $model = match ($resourceType) {
                    'deck' => Deck::query()->findOrFail($resourceId),
                    'course' => Course::query()->findOrFail($resourceId),
                    default => throw new RuntimeException("Unsupported resource type {$resourceType}."),
                };

                $this->performDeletion($resourceType, $model);
                fwrite($socket, "deleted\n");
                fflush($socket);
                usleep(self::DELETION_LOCK_HOLD_MICROSECONDS);
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

    private function performDeletion(string $resourceType, Deck|Course $model): void
    {
        match ($resourceType) {
            'deck' => app(DeleteDeckAction::class)->handle($model),
            'course' => app(DeleteCourseAction::class)->handle($model),
            default => throw new RuntimeException("Unsupported resource type {$resourceType}."),
        };
    }

    private function performUpdate(string $resourceType, Deck|Course $model): void
    {
        match ($resourceType) {
            'deck' => app(UpdateDeckAction::class)->handle(
                $model,
                UpdateDeckData::fromInput('Updated deck', 'Updated description.'),
            ),
            'course' => app(UpdateCourseAction::class)->handle(
                $model,
                UpdateCourseData::fromInput('Updated course', 'Updated description.'),
            ),
            default => throw new RuntimeException("Unsupported resource type {$resourceType}."),
        };
    }

    private function assertWaitedForLock(float $startedAt, string $message): void
    {
        $lockWaitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
        $this->assertGreaterThanOrEqual(350, $lockWaitMilliseconds, $message);
    }

    /** @param resource $socket */
    private function waitForWorker(int $pid, $socket): int
    {
        pcntl_waitpid($pid, $status);
        $workerMessage = trim((string) fgets($socket));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame('committed', $workerMessage);

        return pcntl_wexitstatus($status);
    }

    private function cleanup(User $user): void
    {
        DB::purge();
        SyncFeedEntry::query()->where('user_id', $user->id)->delete();
        Deck::query()->withTrashed()->where('user_id', $user->id)->forceDelete();
        Course::query()->withTrashed()->where('user_id', $user->id)->forceDelete();
        User::query()->whereKey($user->id)->delete();
    }
}
