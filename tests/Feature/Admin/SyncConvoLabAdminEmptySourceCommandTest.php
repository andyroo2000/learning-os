<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncConvoLabAdminEmptySourceCommandTest extends SyncConvoLabAdminProjectionCommandTestCase
{
    public function test_empty_invite_source_requires_confirmation_without_wiping_invites(): void
    {
        $sourceId = (string) Str::uuid();
        $inviteId = (string) Str::uuid();
        $this->insertSourceUser($sourceId, ['email' => 'canonical@example.com']);
        $this->insertSourceInvite($inviteId, 'STALE123');
        $this->runSync()->assertSuccessful();
        DB::connection('convolab_admin_test')->table('InviteCode')->delete();

        $this->runSync()
            ->expectsOutputToContain(
                'The Convo Lab source table [InviteCode] is empty while [admin_invite_codes] is not. Re-run with --allow-empty-source to confirm removal.',
            )
            ->assertFailed();

        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $sourceId]);
        $this->assertDatabaseHas('admin_invite_codes', ['id' => $inviteId]);

        $this->runSync(['--allow-empty-source' => true])
            ->expectsOutput('Synchronized 1 users, 0 invite codes, and 0 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $sourceId]);
        $this->assertDatabaseCount('admin_invite_codes', 0);
    }

    public function test_empty_user_source_requires_confirmation_without_wiping_user_projections(): void
    {
        $sourceId = (string) Str::uuid();
        $inviteId = (string) Str::uuid();
        $this->insertSourceUser($sourceId, ['email' => 'canonical@example.com']);
        $this->insertSourceInvite($inviteId, 'OPEN2026');
        $this->runSync()->assertSuccessful();
        $user = User::query()->where('convolab_id', $sourceId)->sole();
        DB::connection('convolab_admin_test')->table('User')->delete();

        $this->runSync()
            ->expectsOutputToContain(
                'The Convo Lab source table [User] is empty while [admin_user_projections] is not. Re-run with --allow-empty-source to confirm removal.',
            )
            ->assertFailed();

        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $sourceId]);
        $this->assertDatabaseHas('admin_invite_codes', ['id' => $inviteId]);

        $this->runSync(['--allow-empty-source' => true])
            ->expectsOutput('Synchronized 0 users, 1 invite codes, and 0 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseCount('admin_user_projections', 0);
        $this->assertDatabaseHas('admin_invite_codes', ['id' => $inviteId]);
    }
}
