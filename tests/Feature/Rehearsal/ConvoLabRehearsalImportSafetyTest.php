<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportSafetyTest extends ConvoLabRehearsalImportTestCase
{
    public function test_does_not_invent_an_undo_snapshot_when_the_prior_new_queue_position_is_unknown(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $payload = json_decode($source->table('study_review_logs')->value('rawPayloadJson'), true);
        $payload['beforeQueueState'] = 'new';
        $payload['beforeDueAt'] = null;
        $payload['beforeIntroducedAt'] = null;
        $payload['beforeLastReviewedAt'] = null;
        $source->table('study_review_logs')->update(['rawPayloadJson' => json_encode($payload)]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $this->assertNull(CardReviewEvent::query()->sole()->card_state_before);
    }

    public function test_production_truncate_requires_a_database_specific_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->seedConvoLabSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                "Production truncation requires --production-truncate-confirmation=\"TRUNCATE {$targetDatabase}\".",
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_truncate_accepts_the_exact_target_database_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->seedConvoLabSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
            '--skip-media' => true,
            '--allow-production' => true,
            '--production-truncate-confirmation' => "TRUNCATE {$targetDatabase}",
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_production_import_rejects_metadata_only_media(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->seedConvoLabSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
            '--allow-production' => true,
            '--production-truncate-confirmation' => "TRUNCATE {$targetDatabase}",
        ])
            ->expectsOutputToContain(
                'Production import requires --skip-media because Convo Lab does not store media byte sizes.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
