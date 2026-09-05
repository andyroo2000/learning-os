<?php

namespace Tests\Feature\Admin;

use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncConvoLabAdminUserProjectionCommandTest extends SyncConvoLabAdminProjectionCommandTestCase
{
    public function test_sync_preserves_learning_os_owned_avatar_while_updating_other_user_fields(): void
    {
        $userId = (string) Str::uuid();
        $this->insertSourceUser($userId, [
            'email' => 'avatar-owner@example.com',
            'name' => 'Before Sync',
            'avatarUrl' => 'https://example.com/legacy-avatar.jpg',
        ]);
        $this->runSync()->assertSuccessful();

        DB::table('admin_user_projections')->where('convolab_id', $userId)->update([
            'avatar_url' => 'https://storage.googleapis.com/convolab-storage/avatars/new-avatar.jpg',
            'avatar_source_system' => ConvoLabAccountSource::LEARNING_OS,
        ]);
        DB::connection('convolab_admin_test')->table('User')->where('id', $userId)->update([
            'name' => 'After Sync',
            'avatarUrl' => 'https://example.com/stale-avatar.jpg',
        ]);

        $this->runSync()->assertSuccessful();

        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $userId,
            'name' => 'After Sync',
            'avatar_url' => 'https://storage.googleapis.com/convolab-storage/avatars/new-avatar.jpg',
            'avatar_source_system' => ConvoLabAccountSource::LEARNING_OS,
        ]);
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
            'email' => 'UPDATED@example.com',
            'displayName' => 'Updated Display',
            'updatedAt' => '2026-07-22 10:00:00.123',
        ]);
        DB::connection('convolab_admin_test')->table('InviteCode')->where('id', $inviteId)->update([
            'code' => 'SECOND12',
        ]);

        $this->runSync()
            ->expectsOutput('Synchronized 1 users, 1 invite codes, and 0 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_user_projections', 1);
        $this->assertDatabaseCount('admin_invite_codes', 1);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $userId,
            'email' => 'UPDATED@example.com',
            'display_name' => 'Updated Display',
        ]);
        $this->assertDatabaseHas('users', [
            'convolab_id' => $userId,
            'convolab_email_normalized' => 'updated@example.com',
        ]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $inviteId,
            'code' => 'SECOND12',
        ]);
        $this->assertDatabaseMissing('admin_invite_codes', ['id' => $staleInviteId]);
    }

    public function test_syncs_users_and_invites_across_multiple_id_cursor_chunks(): void
    {
        foreach (range(1, 201) as $index) {
            $userId = (string) Str::uuid();
            $this->insertSourceUser($userId, ['email' => "user{$index}@example.com"]);
            $this->insertSourceInvite((string) Str::uuid(), "CODE{$index}");
        }

        $this->runSync()
            ->expectsOutput('Synchronized 201 users, 201 invite codes, and 0 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseCount('admin_user_projections', 201);
        $this->assertDatabaseCount('admin_invite_codes', 201);
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
            ->expectsOutputToContain(
                'The Convo Lab source table [User] is empty while [admin_user_projections] is not. Re-run with --allow-empty-source to confirm removal.',
            )
            ->assertFailed();

        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $sourceId]);
        $this->assertDatabaseCount('admin_invite_codes', 1);

        $this->runSync(['--allow-empty-source' => true])
            ->expectsOutput('Synchronized 0 users, 0 invite codes, and 0 speaker avatars.')
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
        $this->runSync(['--allow-empty-source' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'id' => $canonicalId,
            'convolab_id' => $sourceId,
        ]);
        $this->assertDatabaseMissing('admin_user_projections', ['convolab_id' => $sourceId]);
    }

    public function test_sync_clears_the_compatibility_hash_when_source_account_disconnects_password_login(): void
    {
        $userId = (string) Str::uuid();
        $this->insertSourceUser($userId, [
            'email' => 'oauth@example.com',
            'password' => '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6',
        ]);
        $this->runSync()->assertSuccessful();

        DB::connection('convolab_admin_test')->table('User')->where('id', $userId)->update([
            'password' => null,
        ]);
        $this->runSync()->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'convolab_id' => $userId,
            'convolab_password_hash' => null,
        ]);
    }
}
