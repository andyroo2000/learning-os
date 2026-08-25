<?php

namespace Tests\Feature\Study;

use App\Domain\Japanese\Actions\RunDailyWaniKaniTransferBridgeAction;
use App\Domain\Japanese\Actions\SyncWaniKaniKanjiAction;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Jobs\SyncWaniKaniTransferConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WaniKaniTransferBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-25T12:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_transfer_settings_are_authenticated_validated_and_rate_limited(): void
    {
        $this->assertTrue(Schema::hasColumns('wanikani_connections', [
            'transfer_bridge_enabled',
            'transfer_bridge_enabled_at',
            'transfer_bridge_last_imported_at',
        ]));
        $this->assertTrue(Schema::hasColumns('study_vocab_variant_groups', [
            'wanikani_subject_id',
            'automatic_import_status',
            'automatic_import_error',
            'automatic_imported_at',
        ]));
        $route = app('router')->getRoutes()->match(Request::create(
            '/api/study/wanikani/transfer-bridge',
            'PATCH',
        ));
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('throttle:wanikani-connection-write', $route->gatherMiddleware());

        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])
            ->assertUnauthorized();

        $user = $this->signIn();
        $this->patchJson('/api/study/wanikani/transfer-bridge', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enabled']);
        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => 'sometimes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enabled']);
        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])
            ->assertNotFound();

        $this->connection($user);
        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('wanikani.transferBridge.enabled', true)
            ->assertJsonPath('wanikani.transferBridge.importedVocabularyCount', 0)
            ->assertJsonPath('wanikani.transferBridge.pendingVocabularyCount', 0)
            ->assertJsonPath('wanikani.transferBridge.failedVocabularyCount', 0)
            ->assertJsonPath('wanikani.transferBridge.lastImportedAt', null);

        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->sole();
        $this->assertTrue($connection->transfer_bridge_enabled);
        $this->assertTrue($connection->transfer_bridge_enabled_at->equalTo(now()));

        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => '0'])
            ->assertOk()
            ->assertJsonPath('wanikani.transferBridge.enabled', false);
        $this->assertFalse($connection->fresh()->transfer_bridge_enabled);
    }

    public function test_transfer_settings_conflict_with_an_in_progress_sync(): void
    {
        $user = $this->signIn();
        $this->connection($user);
        $lock = Cache::lock("wanikani-sync:user:{$user->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])
                ->assertConflict()
                ->assertExactJson(['message' => 'A WaniKani sync is already in progress.']);
        } finally {
            $lock->release();
        }
    }

    public function test_database_rejects_duplicate_wanikani_subject_groups_for_one_user(): void
    {
        $user = User::factory()->create();
        $this->vocabulary($user, 400, '橋', now()->subHour(), ['はし'], ['Bridge']);
        StudyVocabVariantGroup::factory()->for($user)->create(['wanikani_subject_id' => 400]);

        $this->expectException(QueryException::class);

        StudyVocabVariantGroup::factory()->for($user)->create(['wanikani_subject_id' => 400]);
    }

    public function test_status_reports_imported_pending_and_failed_vocabulary_counts(): void
    {
        $user = $this->signIn();
        $this->connection($user, [
            'transfer_bridge_enabled' => true,
            'transfer_bridge_enabled_at' => now()->subDay(),
            'transfer_bridge_last_imported_at' => now()->subHour(),
        ]);
        foreach ([401, 402, 403] as $subjectId) {
            $this->vocabulary($user, $subjectId, '橋'.$subjectId, now()->subHour());
        }
        StudyVocabVariantGroup::factory()->for($user)->create([
            'wanikani_subject_id' => 401,
            'automatic_import_status' => AutomaticStudyVocabImportStatus::Imported,
            'automatic_imported_at' => now()->subHour(),
        ]);
        StudyVocabVariantGroup::factory()->for($user)->create([
            'wanikani_subject_id' => 402,
            'automatic_import_status' => AutomaticStudyVocabImportStatus::Generating,
        ]);
        StudyVocabVariantGroup::factory()->for($user)->create([
            'wanikani_subject_id' => 403,
            'automatic_import_status' => AutomaticStudyVocabImportStatus::Error,
            'automatic_import_error' => 'Provider unavailable.',
        ]);

        $this->getJson('/api/study/known-kanji')
            ->assertOk()
            ->assertJsonPath('wanikani.transferBridge.enabled', true)
            ->assertJsonPath('wanikani.transferBridge.importedVocabularyCount', 1)
            ->assertJsonPath('wanikani.transferBridge.pendingVocabularyCount', 1)
            ->assertJsonPath('wanikani.transferBridge.failedVocabularyCount', 1)
            ->assertJsonPath('wanikani.transferBridge.lastImportedAt', '2026-08-25T11:00:00.000000Z');
    }

    public function test_daily_schedule_queues_only_enabled_connections_with_retry_envelope(): void
    {
        Queue::fake();
        $enabled = User::factory()->create();
        $this->connection($enabled, [
            'transfer_bridge_enabled' => true,
            'transfer_bridge_enabled_at' => now(),
        ]);
        $this->connection(User::factory()->create());

        $this->assertSame(
            ['queued' => 1, 'failed' => 0],
            app(RunDailyWaniKaniTransferBridgeAction::class)->handle(),
        );
        Queue::assertPushedOn(
            SyncWaniKaniTransferConnection::QUEUE_NAME,
            SyncWaniKaniTransferConnection::class,
            function (SyncWaniKaniTransferConnection $job) use ($enabled): bool {
                $this->assertInstanceOf(ShouldBeUnique::class, $job);
                $this->assertSame(3, $job->tries);
                $this->assertSame(300, $job->timeout);
                $this->assertSame([60, 300], $job->backoff());

                return $job->userId === $enabled->id;
            },
        );

        $event = collect(Schedule::events())
            ->first(fn ($event): bool => $event->description === 'wanikani:daily-transfer-bridge');
        $this->assertNotNull($event);
        $this->assertSame('15 8 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }

    public function test_daily_dispatch_isolates_a_queue_backend_failure(): void
    {
        Exceptions::fake();
        $user = User::factory()->create();
        $this->connection($user, [
            'transfer_bridge_enabled' => true,
            'transfer_bridge_enabled_at' => now(),
        ]);
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        $this->assertSame(
            ['queued' => 0, 'failed' => 1],
            app(RunDailyWaniKaniTransferBridgeAction::class)->handle(),
        );
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => $exception->getMessage() === 'queue unavailable');
    }

    public function test_daily_job_ignores_disconnect_disable_and_manual_sync_races(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'transfer_bridge_enabled' => true,
            'transfer_bridge_enabled_at' => now(),
        ]);
        $job = new SyncWaniKaniTransferConnection($user->id);
        $lock = Cache::lock("wanikani-sync:user:{$user->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $job->handle(app(SyncWaniKaniKanjiAction::class));
        } finally {
            $lock->release();
        }

        $connection->transfer_bridge_enabled = false;
        $connection->save();
        $job->handle(app(SyncWaniKaniKanjiAction::class));

        $connection->delete();
        $job->handle(app(SyncWaniKaniKanjiAction::class));

        $this->assertDatabaseCount('wanikani_connections', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function connection(User $user, array $attributes = []): WaniKaniConnection
    {
        $connection = new WaniKaniConnection;
        $connection->user_id = $user->id;
        $connection->api_token = 'test-token';
        foreach ($attributes as $key => $value) {
            $connection->{$key} = $value;
        }
        $connection->save();

        return $connection;
    }

    /** @param list<string> $readings @param list<string> $meanings */
    private function vocabulary(
        User $user,
        int $subjectId,
        string $characters,
        mixed $passedAt,
        array $readings = ['ふるい'],
        array $meanings = ['Old'],
        bool $hidden = false,
    ): void {
        $now = now();
        DB::table('wanikani_subjects')->insert([
            'subject_id' => $subjectId,
            'subject_type' => 'vocabulary',
            'characters' => $characters,
            'normalized_key' => $characters,
            'readings' => json_encode($readings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'meanings' => json_encode($meanings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'hidden_at' => null,
            'source_updated_at' => $now,
            'matcher_version' => 'test-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_wanikani_assignments')->insert([
            'user_id' => $user->id,
            'subject_id' => $subjectId,
            'srs_stage' => 5,
            'passed_at' => $passedAt,
            'burned_at' => null,
            'hidden' => $hidden,
            'source_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
