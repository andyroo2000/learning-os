<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\CreateContentCourseAction;
use App\Domain\Content\Actions\CreateContentEpisodeAction;
use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCreationFingerprint;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ContentCreateIdempotencyPostgresTest extends TestCase
{
    public function test_episode_create_recovers_when_a_non_cooperating_writer_wins_the_primary_key_race(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise concurrent create recovery.');
        }

        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $episodeId = (string) Str::uuid();
        $data = CreateContentEpisodeData::fromInput(
            userId: $user->id,
            convoLabUserId: $convoLabUserId,
            title: 'Non-cooperating writer',
            sourceText: 'Same canonical source.',
            targetLanguage: 'ja',
            nativeLanguage: 'en',
            id: $episodeId,
        );
        $inserted = false;

        DB::listen(function ($query) use (&$inserted, $data): void {
            $sql = strtolower($query->sql);
            if ($inserted
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'from "content_episodes"')
                || ! in_array($data->id, $query->bindings, true)
                || DB::transactionLevel() === 0) {
                return;
            }

            $inserted = true;
            $connectionName = 'idempotency_race_writer';
            config()->set("database.connections.{$connectionName}", config('database.connections.pgsql'));
            $connection = DB::connection($connectionName);
            $connection->table('content_episodes')->insert([
                'id' => $data->id,
                'user_id' => $data->userId,
                'convolab_user_id' => $data->convoLabUserId,
                'source_system' => ContentSourceSystem::LEARNING_OS,
                'title' => $data->title,
                'source_text' => $data->sourceText,
                'target_language' => $data->targetLanguage,
                'native_language' => $data->nativeLanguage,
                'content_type' => 'dialogue',
                'auto_generate_audio' => true,
                'status' => 'draft',
                'is_sample_content' => false,
                'audio_speed' => 'medium',
                'creation_fingerprint' => ContentCreationFingerprint::episode($data),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::disconnect($connectionName);
        });

        try {
            $result = app(CreateContentEpisodeAction::class)->handle($data);

            $this->assertTrue($inserted);
            $this->assertFalse($result->wasCreated);
            $this->assertSame($episodeId, $result->episode->id);
            $this->assertSame(1, ContentEpisode::query()->whereKey($episodeId)->count());
        } finally {
            ContentEpisode::query()->whereKey($episodeId)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    public function test_concurrent_client_uuid_episode_and_course_creates_converge_on_one_graph(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise concurrent create recovery.');
        }

        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $episodeId = (string) Str::uuid();
        $courseId = (string) Str::uuid();

        try {
            $episodeResults = $this->runConcurrentWorkers(function () use ($user, $convoLabUserId, $episodeId): string {
                $result = app(CreateContentEpisodeAction::class)->handle(CreateContentEpisodeData::fromInput(
                    userId: $user->id,
                    convoLabUserId: $convoLabUserId,
                    title: 'Concurrent Episode',
                    sourceText: 'Concurrent source text.',
                    targetLanguage: 'ja',
                    nativeLanguage: 'en',
                    id: $episodeId,
                ));

                return $result->wasCreated ? 'created' : 'existing';
            });

            $this->assertEqualsCanonicalizing(['created', 'existing'], $episodeResults);
            $this->assertSame(1, ContentEpisode::query()->whereKey($episodeId)->count());

            $courseResults = $this->runConcurrentWorkers(function () use (
                $user,
                $convoLabUserId,
                $episodeId,
                $courseId,
            ): string {
                $result = app(CreateContentCourseAction::class)->handle(CreateContentCourseData::fromInput(
                    $user->id,
                    $convoLabUserId,
                    [
                        'id' => $courseId,
                        'title' => 'Concurrent Course',
                        'description' => 'No provider side effect.',
                        'episodeIds' => [$episodeId],
                        'nativeLanguage' => 'en',
                        'targetLanguage' => 'ja',
                    ],
                ));

                return $result->wasCreated ? 'created' : 'existing';
            });

            $this->assertEqualsCanonicalizing(['created', 'existing'], $courseResults);
            $this->assertSame(1, ContentCourse::query()->whereKey($courseId)->count());
            $this->assertSame(1, ContentEpisodeCourse::query()->where('convolab_course_id', $courseId)->count());
        } finally {
            ContentEpisodeCourse::query()->where('convolab_course_id', $courseId)->delete();
            ContentCourse::query()->whereKey($courseId)->delete();
            ContentEpisode::query()->whereKey($episodeId)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    /**
     * @param  callable(): string  $operation
     * @return list<string>
     */
    private function runConcurrentWorkers(callable $operation): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create PostgreSQL create-race sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork PostgreSQL create-race worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                fwrite($sockets[1], "ready\n");
                fflush($sockets[1]);
                fgets($sockets[1]);
                fwrite($sockets[1], $operation()."\n");
                fflush($sockets[1]);
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($sockets[1], $exception::class.': '.$exception->getMessage()."\n");
                fflush($sockets[1]);
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);

        try {
            $this->assertSame('ready', trim((string) fgets($sockets[0])));
            DB::purge();
            fwrite($sockets[0], "go\n");
            fflush($sockets[0]);
            $parentResult = $operation();
            pcntl_waitpid($pid, $status);
            $childResult = trim((string) fgets($sockets[0]));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $childResult);

            return [$parentResult, $childResult];
        } finally {
            fclose($sockets[0]);
            if (! isset($status)) {
                pcntl_waitpid($pid, $status);
            }
        }
    }
}
