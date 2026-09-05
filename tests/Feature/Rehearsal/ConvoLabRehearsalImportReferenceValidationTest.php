<?php

namespace Tests\Feature\Rehearsal;

use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportReferenceValidationTest extends ConvoLabRehearsalImportTestCase
{
    public function test_rejects_a_card_whose_note_is_owned_by_another_user(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('study_notes')->update(['userId' => 'source-user-2']);

        $this->assertImportFailsWith(
            'Convo Lab card ['.self::SOURCE_CARD_ID.'] references a note owned by another user.',
        );

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_rejects_a_review_with_a_missing_card_instead_of_discarding_it(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('study_review_logs')
            ->update(['cardId' => 'missing-card']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Missing imported card mapping for review [source-review-1].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_rejects_shared_media_paths_across_users(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $source->table('User')->insert([
            'id' => self::SOURCE_OTHER_USER_ID,
            'email' => 'grace@example.com',
            'password' => null,
            'name' => 'Grace',
            'displayName' => null,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-14 10:00:00',
            'updatedAt' => '2026-07-14 10:00:00',
        ]);
        $media = (array) $source->table('study_media')->where('id', 'source-media-1')->first();
        $source->table('study_media')->insert([
            ...$media,
            'id' => 'source-media-other-user',
            'userId' => self::SOURCE_OTHER_USER_ID,
            'importJobId' => null,
        ]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Media path [study-media/'.self::SOURCE_USER_ID.'/neko.mp3] is shared by multiple Convo Lab users.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }
}
