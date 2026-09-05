<?php

namespace Tests\Feature\Rehearsal;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ConvoLabRehearsalImportSourceValidationTest extends ConvoLabRehearsalImportTestCase
{
    public function test_refuses_to_import_into_a_non_empty_target_without_truncate(): void
    {
        $this->seedConvoLabSourceData();
        $existingUser = User::factory()->create();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
        ])
            ->expectsOutputToContain('Learning OS target table [users] is not empty.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $existingUser->id]);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_rejects_duplicate_source_emails_instead_of_merging_users(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('User')->insert([
            'id' => self::SOURCE_DUPLICATE_EMAIL_USER_ID,
            'email' => 'ADA@example.com',
            'password' => null,
            'name' => 'Other Ada',
            'displayName' => null,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-14 10:00:00',
            'updatedAt' => '2026-07-14 10:00:00',
        ]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Multiple Convo Lab users share email [ADA@example.com].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_an_unsupported_source_password_hash(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('User')->update(['password' => 'not-a-password-hash']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Convo Lab user ['.self::SOURCE_USER_ID.'] has an unsupported password hash.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_normalizes_node_bcrypt_passwords_for_php_authentication(): void
    {
        $this->seedConvoLabSourceData();
        $nodeBcryptHash = '$2b$'.substr(Hash::make('correct horse battery staple'), 4);
        DB::connection('convolab_test_source')->table('User')->update(['password' => $nodeBcryptHash]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $importedHash = User::query()->sole()->password;
        $this->assertStringStartsWith('$2y$', $importedHash);
        $this->assertTrue(Hash::check('correct horse battery staple', $importedHash));
    }

    public function test_rejects_card_media_links_across_user_ownership_boundaries(): void
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
        $source->table('study_media')->update(['userId' => self::SOURCE_OTHER_USER_ID]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain(
                'Card ['.self::SOURCE_CARD_ID.'] references media [source-media-1] owned by another user.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('card_media', 0);
    }
}
