<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\PruneTerminalContentGenerationRequestsAction;
use App\Domain\Content\Actions\ReconcileDispatchedContentGenerationRequestAction;
use App\Domain\Content\Actions\ReserveContentGenerationRequestAction;
use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentGenerationRequestFingerprint;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ContentGenerationRequestPostgresTest extends TestCase
{
    public function test_concurrent_owner_scoped_reservations_converge_on_one_ledger_row(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise concurrent ledger recovery.');
        }
        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $clientRequestId = (string) Str::uuid();
        $data = GenerateContentDialogueData::fromInput([
            'episodeId' => (string) Str::uuid(),
            'speakers' => [
                ['name' => 'Aiko', 'voiceId' => 'Aiko', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => null],
                ['name' => 'Ken', 'voiceId' => 'Ken', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
            ],
            'variationCount' => 3,
            'dialogueLength' => 6,
            'jlptLevel' => 'N4',
        ]);

        try {
            $results = $this->runConcurrentWorkers(function () use (
                $clientRequestId,
                $convoLabUserId,
                $data,
                $user,
            ): string {
                $result = app(ReserveContentGenerationRequestAction::class)->handle(
                    $user->id,
                    $convoLabUserId,
                    $clientRequestId,
                    ContentGenerationRequestState::DIALOGUE_OPERATION,
                    'episode',
                    $data->episodeId,
                    ContentGenerationRequestFingerprint::dialogue($data),
                    $data->toArray(),
                );

                return $result->wasCreated ? 'created' : 'existing';
            });

            $this->assertEqualsCanonicalizing(['created', 'existing'], $results);
            $this->assertSame(1, ContentGenerationRequest::query()
                ->where('convolab_user_id', $convoLabUserId)
                ->where('client_request_id', $clientRequestId)
                ->count());
        } finally {
            ContentGenerationRequest::query()->where('convolab_user_id', $convoLabUserId)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    public function test_overlapping_reconciliation_workers_terminalize_a_course_request_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise concurrent ledger recovery.');
        }
        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $course = ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Reconciled Course',
            'status' => 'ready',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'fishaudio:test',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
            'generation_attempt' => 1,
        ]);
        $request = ContentGenerationRequest::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'client_request_id' => (string) Str::uuid(),
            'operation' => ContentGenerationRequestState::COURSE_OPERATION,
            'resource_type' => 'course',
            'resource_id' => $course->id,
            'input_fingerprint' => hash('sha256', $course->id),
            'input_payload' => [],
            'state' => ContentGenerationRequestState::ACTIVE,
            'job_id' => $course->id,
            'job_attempt' => 1,
            'dispatched_at' => now(),
        ]);

        try {
            $results = $this->runConcurrentWorkers(fn (): string => app(
                ReconcileDispatchedContentGenerationRequestAction::class,
            )->handle($request->id) ? 'updated' : 'terminal');

            $this->assertEqualsCanonicalizing(['updated', 'terminal'], $results);
            $request->refresh();
            $this->assertSame(ContentGenerationRequestState::COMPLETED, $request->state);
            $this->assertSame(200, $request->response_status);
            $this->assertNotNull($request->finished_at);
        } finally {
            ContentGenerationRequest::query()->whereKey($request->id)->delete();
            ContentCourse::query()->whereKey($course->id)->delete();
            User::query()->whereKey($user->id)->delete();
        }
    }

    public function test_overlapping_prune_workers_delete_an_expired_terminal_request_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise concurrent ledger pruning.');
        }
        $this->assertTrue(function_exists('pcntl_fork'), 'The PostgreSQL concurrency gate requires pcntl_fork().');

        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $request = ContentGenerationRequest::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'client_request_id' => (string) Str::uuid(),
            'operation' => ContentGenerationRequestState::COURSE_OPERATION,
            'resource_type' => 'course',
            'resource_id' => (string) Str::uuid(),
            'input_fingerprint' => hash('sha256', (string) Str::uuid()),
            'input_payload' => [],
            'state' => ContentGenerationRequestState::COMPLETED,
            'response_status' => 200,
            'finished_at' => now()->subDays(ContentGenerationRequest::TERMINAL_RETENTION_DAYS + 1),
        ]);

        try {
            $results = $this->runConcurrentWorkers(function (): string {
                $result = app(PruneTerminalContentGenerationRequestsAction::class)->handle(limit: 1);

                return sprintf('%d:%d', $result->deleted, $result->skipped);
            });

            $counts = array_map(
                fn (string $result): array => array_map('intval', explode(':', $result)),
                $results,
            );
            $this->assertSame(1, array_sum(array_column($counts, 0)));
            $this->assertContains([1, 0], $counts);
            $this->assertContains($counts[0] === [1, 0] ? $counts[1] : $counts[0], [[0, 0], [0, 1]]);
            $this->assertDatabaseMissing('content_generation_requests', ['id' => $request->id]);
        } finally {
            ContentGenerationRequest::query()->whereKey($request->id)->delete();
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
            throw new RuntimeException('Unable to create PostgreSQL ledger-race sockets.');
        }

        DB::disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork PostgreSQL ledger-race worker.');
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
