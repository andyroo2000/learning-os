<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SyncConvoLabAdminProjectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = storage_path('framework/testing/convolab-admin-'.uniqid().'.sqlite');
        touch($this->sourceDatabase);
        config()->set('database.connections.convolab_admin_test', [
            'driver' => 'sqlite',
            'database' => $this->sourceDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('convolab_admin_test');
        $this->createSourceSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_admin_test');

        if (isset($this->sourceDatabase) && is_file($this->sourceDatabase)) {
            unlink($this->sourceDatabase);
        }

        parent::tearDown();
    }

    public function test_syncs_users_and_invites_into_the_canonical_projection(): void
    {
        $existing = User::factory()->create([
            'email' => 'ADA@example.com',
            'name' => 'Old Name',
            'email_verified_at' => '2026-07-01 08:00:00',
            'created_at' => '2026-07-10 08:00:00',
            'updated_at' => '2026-07-10 09:00:00',
        ]);
        $adaId = (string) Str::uuid();
        $graceId = (string) Str::uuid();
        $adaInviteId = (string) Str::uuid();
        $availableInviteId = (string) Str::uuid();
        $this->insertSourceUser($adaId, [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'displayName' => 'Ada',
            'avatarColor' => 'teal',
            'avatarUrl' => 'https://example.com/ada.png',
            'role' => 'admin',
            'preferredStudyLanguage' => 'ja',
            'preferredNativeLanguage' => 'en',
            'onboardingCompleted' => true,
            'emailVerifiedAt' => '2026-07-20 09:00:00.123',
        ]);
        $this->insertSourceUser($graceId, [
            'email' => 'grace@example.com',
            'name' => 'Grace Hopper',
            'role' => 'moderator',
        ]);
        $this->insertSourceInvite($adaInviteId, 'ADA2026', $adaId, '2026-07-21 10:00:00.456');
        $this->insertSourceInvite($availableInviteId, 'OPEN2026');

        $this->artisan('admin:sync-convolab', [
            '--source-connection' => 'convolab_admin_test',
        ])
            ->expectsOutput('Synchronized 2 users and 2 invite codes.')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'convolab_id' => $adaId,
            'email' => 'ADA@example.com',
            'name' => 'Old Name',
            'email_verified_at' => '2026-07-01 08:00:00',
            'created_at' => '2026-07-10 08:00:00',
            'updated_at' => '2026-07-10 09:00:00',
        ]);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $adaId,
            'user_id' => $existing->id,
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
            'display_name' => 'Ada',
            'avatar_color' => 'teal',
            'avatar_url' => 'https://example.com/ada.png',
            'role' => 'admin',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'onboarding_completed' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'convolab_id' => $graceId,
            'email' => 'grace@example.com',
            'name' => 'Grace Hopper',
        ]);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $graceId,
            'role' => 'moderator',
            'onboarding_completed' => false,
        ]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $adaInviteId,
            'code' => 'ADA2026',
            'used_by' => $existing->id,
            'convolab_used_by' => $adaId,
        ]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $availableInviteId,
            'code' => 'OPEN2026',
            'used_by' => null,
            'convolab_used_by' => null,
        ]);
        $this->assertNotNull(User::query()->where('convolab_id', $graceId)->value('password'));
    }

    public function test_sync_is_idempotent_updates_rows_and_removes_stale_invites(): void
    {
        $userId = (string) Str::uuid();
        $inviteId = (string) Str::uuid();
        $staleInviteId = (string) Str::uuid();
        $this->insertSourceUser($userId, ['email' => 'ada@example.com']);
        $this->insertSourceInvite($inviteId, 'FIRST123', $userId);
        DB::table('admin_invite_codes')->insert([
            'id' => $staleInviteId,
            'code' => 'STALE123',
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => '2026-07-19 10:00:00',
        ]);

        $this->runSync()->assertSuccessful();
        DB::connection('convolab_admin_test')->table('User')->where('id', $userId)->update([
            'displayName' => 'Updated Display',
            'updatedAt' => '2026-07-22 10:00:00.123',
        ]);
        DB::connection('convolab_admin_test')->table('InviteCode')->where('id', $inviteId)->update([
            'code' => 'SECOND12',
        ]);

        $this->runSync()
            ->expectsOutput('Synchronized 1 users and 1 invite codes.')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_user_projections', 1);
        $this->assertDatabaseCount('admin_invite_codes', 1);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $userId,
            'display_name' => 'Updated Display',
        ]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $inviteId,
            'code' => 'SECOND12',
        ]);
        $this->assertDatabaseMissing('admin_invite_codes', ['id' => $staleInviteId]);
    }

    public function test_empty_source_removes_invites_but_preserves_canonical_users(): void
    {
        $sourceId = (string) Str::uuid();
        $this->insertSourceUser($sourceId, ['email' => 'canonical@example.com']);
        $this->runSync()->assertSuccessful();
        $user = User::query()->where('convolab_id', $sourceId)->sole();
        DB::connection('convolab_admin_test')->table('User')->delete();
        DB::table('admin_invite_codes')->insert([
            'id' => (string) Str::uuid(),
            'code' => 'STALE123',
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => now(),
        ]);

        $this->runSync()
            ->expectsOutput('Synchronized 0 users and 0 invite codes.')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('admin_user_projections', 0);
        $this->assertDatabaseCount('admin_invite_codes', 0);
    }

    public function test_users_removed_from_the_source_are_hidden_without_deleting_canonical_accounts(): void
    {
        $sourceId = (string) Str::uuid();
        $this->insertSourceUser($sourceId, ['email' => 'ada@example.com']);
        $this->runSync()->assertSuccessful();
        $canonicalId = User::query()->where('convolab_id', $sourceId)->value('id');

        DB::connection('convolab_admin_test')->table('User')->delete();
        $this->runSync()->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'id' => $canonicalId,
            'convolab_id' => $sourceId,
        ]);
        $this->assertDatabaseMissing('admin_user_projections', ['convolab_id' => $sourceId]);
    }

    public function test_sync_rolls_back_all_target_changes_when_an_invite_references_an_unknown_user(): void
    {
        $userId = (string) Str::uuid();
        $inviteId = (string) Str::uuid();
        $this->insertSourceUser($userId, ['email' => 'ada@example.com']);
        $this->insertSourceInvite($inviteId, 'BROKEN12', (string) Str::uuid());

        $this->runSync()
            ->expectsOutputToContain("Convo Lab invite code [{$inviteId}] references an unknown user.")
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_user_projections', 0);
        $this->assertDatabaseCount('admin_invite_codes', 0);
    }

    public function test_sync_rejects_duplicate_normalized_source_emails(): void
    {
        $this->insertSourceUser((string) Str::uuid(), ['email' => 'ada@example.com']);
        $this->insertSourceUser((string) Str::uuid(), ['email' => ' ADA@example.com ']);

        $this->runSync()
            ->expectsOutputToContain('Convo Lab users must have unique IDs and email addresses.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_sync_rejects_source_identity_conflicts_without_reassigning_accounts(): void
    {
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $first = User::factory()->create(['email' => 'first@example.com']);
        $second = User::factory()->create(['email' => 'second@example.com']);
        DB::table('users')->where('id', $first->id)->update(['convolab_id' => $firstId]);
        DB::table('users')->where('id', $second->id)->update(['convolab_id' => $secondId]);
        $this->insertSourceUser($firstId, ['email' => 'second@example.com']);

        $this->runSync()
            ->expectsOutputToContain(
                "Convo Lab user [{$firstId}] conflicts with an existing canonical email account.",
            )
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $first->id, 'email' => 'first@example.com']);
        $this->assertDatabaseHas('users', ['id' => $second->id, 'email' => 'second@example.com']);
    }

    public function test_sync_rejects_ambiguous_case_insensitive_canonical_email_matches(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        User::factory()->create(['email' => 'ADA@example.com']);
        $sourceId = (string) Str::uuid();
        $this->insertSourceUser($sourceId, ['email' => 'Ada@example.com']);

        $this->runSync()
            ->expectsOutputToContain(
                "Convo Lab user [{$sourceId}] matches multiple canonical email accounts.",
            )
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['convolab_id' => $sourceId]);
    }

    public function test_sync_rejects_invalid_or_oversized_source_values_with_context(): void
    {
        $userId = (string) Str::uuid();
        $this->insertSourceUser($userId, [
            'email' => 'ada@example.com',
            'role' => str_repeat('a', 33),
        ]);

        $this->runSync()
            ->expectsOutputToContain('Convo Lab source field [role] exceeds 32 characters.')
            ->assertFailed();

        DB::connection('convolab_admin_test')->table('User')->delete();
        $this->insertSourceUser($userId, ['email' => 'not-an-email']);
        $this->runSync()
            ->expectsOutputToContain("Convo Lab user [{$userId}] has an invalid email.")
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_sync_enforces_character_lengths_for_multibyte_profile_fields(): void
    {
        $userId = (string) Str::uuid();
        $accepted = str_repeat('日', 255);
        $this->insertSourceUser($userId, [
            'email' => 'ada@example.com',
            'displayName' => $accepted,
        ]);

        $this->runSync()->assertSuccessful();
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $userId,
            'display_name' => $accepted,
        ]);

        DB::connection('convolab_admin_test')->table('User')->where('id', $userId)->update([
            'displayName' => str_repeat('日', 256),
        ]);
        $this->runSync()
            ->expectsOutputToContain('Convo Lab source field [displayName] exceeds 255 characters.')
            ->assertFailed();

        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $userId,
            'display_name' => $accepted,
        ]);
    }

    public function test_sync_refuses_production_without_an_explicit_flag(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->runSync()
            ->expectsOutput('This command must not run in production without --allow-production.')
            ->assertFailed();
    }

    public function test_sync_rejects_the_target_database_as_the_source(): void
    {
        $this->artisan('admin:sync-convolab', [
            '--source-connection' => DB::getDefaultConnection(),
        ])
            ->expectsOutputToContain('Source and target databases must differ.')
            ->assertFailed();
    }

    private function runSync(): PendingCommand
    {
        return $this->artisan('admin:sync-convolab', [
            '--source-connection' => 'convolab_admin_test',
        ]);
    }

    private function createSourceSchema(): void
    {
        Schema::connection('convolab_admin_test')->create('User', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email');
            $table->string('name');
            $table->string('displayName')->nullable();
            $table->string('avatarColor')->nullable();
            $table->text('avatarUrl')->nullable();
            $table->string('role');
            $table->string('preferredStudyLanguage');
            $table->string('preferredNativeLanguage');
            $table->boolean('onboardingCompleted');
            $table->timestamp('emailVerifiedAt')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
        Schema::connection('convolab_admin_test')->create('InviteCode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('usedBy')->nullable();
            $table->timestamp('usedAt')->nullable();
            $table->timestamp('createdAt');
        });
    }

    /** @param array<string, mixed> $attributes */
    private function insertSourceUser(string $id, array $attributes = []): void
    {
        DB::connection('convolab_admin_test')->table('User')->insert(array_merge([
            'id' => $id,
            'email' => 'user@example.com',
            'name' => 'Source User',
            'displayName' => null,
            'avatarColor' => 'indigo',
            'avatarUrl' => null,
            'role' => 'user',
            'preferredStudyLanguage' => 'ja',
            'preferredNativeLanguage' => 'en',
            'onboardingCompleted' => false,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-20 10:00:00.123',
            'updatedAt' => '2026-07-20 11:00:00.456',
        ], $attributes));
    }

    private function insertSourceInvite(
        string $id,
        string $code,
        ?string $usedBy = null,
        ?string $usedAt = null,
    ): void {
        DB::connection('convolab_admin_test')->table('InviteCode')->insert([
            'id' => $id,
            'code' => $code,
            'usedBy' => $usedBy,
            'usedAt' => $usedAt,
            'createdAt' => '2026-07-21 10:00:00.123',
        ]);
    }
}
