<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncConvoLabAdminInviteCommandTest extends SyncConvoLabAdminProjectionCommandTestCase
{
    public function test_sync_does_not_resurrect_a_tombstoned_source_invite(): void
    {
        $sourceId = (string) Str::uuid();
        $inviteId = (string) Str::uuid();
        $this->insertSourceUser($sourceId, ['email' => 'canonical@example.com']);
        $this->insertSourceInvite($inviteId, 'DELETED1');
        $this->runSync()->assertSuccessful();

        DB::table('admin_invite_code_tombstones')->insert([
            'invite_code_id' => $inviteId,
            'deleted_at' => now(),
        ]);
        DB::table('admin_invite_codes')->where('id', $inviteId)->delete();

        $this->runSync()
            ->expectsOutput('Synchronized 1 users, 1 invite codes, and 0 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('admin_invite_codes', ['id' => $inviteId]);
        $this->assertDatabaseHas('admin_invite_code_tombstones', [
            'invite_code_id' => $inviteId,
        ]);
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
}
