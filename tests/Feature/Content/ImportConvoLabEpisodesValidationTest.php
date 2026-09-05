<?php

namespace Tests\Feature\Content;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportConvoLabEpisodesValidationTest extends ImportConvoLabEpisodesTestCase
{
    public function test_rejects_the_target_database_as_the_source(): void
    {
        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => DB::getDefaultConnection(),
        ])
            ->expectsOutputToContain('Source and target databases must differ.')
            ->assertFailed();
    }

    public function test_rejects_duplicate_normalized_source_emails(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();
        DB::connection('convolab_content_test')->table('User')->insert([
            'id' => (string) Str::uuid(),
            'email' => ' ADA@example.com ',
        ]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain('Source contains duplicate normalized user email [ada@example.com].')
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_rejects_unexpected_nulls_in_source_required_fields_with_context(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();
        DB::connection('convolab_content_test')->table('Sentence')
            ->where('id', $ids['sentence'])
            ->update(['metadata' => null]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain("Sentence [{$ids['sentence']}] metadata must not be null.")
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_rejects_unsupported_source_content_types_instead_of_hiding_imported_rows(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();
        DB::connection('convolab_content_test')->table('Episode')
            ->where('id', $ids['dialogueEpisode'])
            ->update(['contentType' => 'quiz']);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain("Episode [{$ids['dialogueEpisode']}] has unsupported content type [quiz].")
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_production_truncate_requires_the_exact_target_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                "Production replacement requires --production-truncate-confirmation=\"TRUNCATE {$targetDatabase}\".",
            )
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }
}
