<?php

namespace Tests\Feature\Admin;

use App\Domain\Auth\Actions\RegisterConvoLabUserAction;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncConvoLabAdminProjectionCommandTest extends SyncConvoLabAdminProjectionCommandTestCase
{
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
            'password' => '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6',
            'name' => 'Ada Lovelace',
            'displayName' => 'Ada',
            'avatarColor' => 'teal',
            'avatarUrl' => 'https://example.com/ada.png',
            'role' => 'admin',
            'preferredStudyLanguage' => 'ja',
            'preferredNativeLanguage' => 'en',
            'proficiencyLevel' => 'N3',
            'onboardingCompleted' => true,
            'seenSampleContentGuide' => true,
            'seenCustomContentGuide' => true,
            'emailVerified' => true,
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
            ->expectsOutput('Synchronized 2 users, 2 invite codes, and 0 speaker avatars.')
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
            'avatar_source_system' => ConvoLabAccountSource::CONVOLAB,
            'role' => 'admin',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'proficiency_level' => 'N3',
            'onboarding_completed' => true,
            'seen_sample_content_guide' => true,
            'seen_custom_content_guide' => true,
            'email_verified' => true,
            'email_verified_at' => '2026-07-20 09:00:00.123',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'convolab_email_normalized' => 'ada@example.com',
            'convolab_password_hash' => '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6',
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

    public function test_sync_preserves_learning_os_owned_accounts_and_invites(): void
    {
        $ownedInviteId = (string) Str::uuid();
        DB::table('admin_invite_codes')->insert([
            'id' => $ownedInviteId,
            'code' => 'TARGET01',
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => '2026-07-21 10:00:00.123',
            'source_system' => 'convolab',
        ]);
        $owned = app(RegisterConvoLabUserAction::class)->handle(
            'target@example.com',
            'target password',
            'Target User',
            'TARGET01',
        )->account;
        $originalHash = User::query()->findOrFail($owned->user_id)->getAttribute('convolab_password_hash');

        $targetOnlyInviteId = (string) Str::uuid();
        DB::table('admin_invite_codes')->insert([
            'id' => $targetOnlyInviteId,
            'code' => 'TARGET02',
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => '2026-07-21 10:00:00.123',
            'source_system' => 'convolab',
        ]);
        $targetOnly = app(RegisterConvoLabUserAction::class)->handle(
            'only-target@example.com',
            'target password',
            'Only Target',
            'TARGET02',
        )->account;

        $this->insertSourceUser($owned->convolab_id, [
            'email' => 'stale-source@example.com',
            'password' => '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6',
            'name' => 'Stale Source',
        ]);
        $this->insertSourceInvite(
            $ownedInviteId,
            'STALE001',
            $owned->convolab_id,
            '2026-07-21 11:00:00.123',
        );

        $this->runSync()
            ->expectsOutput('Synchronized 1 users, 1 invite codes, and 0 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $owned->convolab_id,
            'email' => 'target@example.com',
            'name' => 'Target User',
            'source_system' => 'learning_os',
        ]);
        $this->assertSame(
            $originalHash,
            User::query()->findOrFail($owned->user_id)->getAttribute('convolab_password_hash'),
        );
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $ownedInviteId,
            'code' => 'TARGET01',
            'source_system' => 'learning_os',
        ]);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $targetOnly->convolab_id,
            'source_system' => 'learning_os',
        ]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $targetOnlyInviteId,
            'source_system' => 'learning_os',
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
}
