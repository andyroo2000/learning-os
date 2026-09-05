<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncConvoLabAdminValidationCommandTest extends SyncConvoLabAdminProjectionCommandTestCase
{
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

    public function test_sync_rejects_unsupported_password_hashes_without_changing_credentials(): void
    {
        $userId = (string) Str::uuid();
        $this->insertSourceUser($userId, [
            'email' => 'ada@example.com',
            'password' => str_repeat('x', 60),
        ]);

        $this->runSync()
            ->expectsOutputToContain("Convo Lab user [{$userId}] has an unsupported password hash.")
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_user_projections', 0);
    }

    public function test_sync_rejects_bcrypt_hashes_with_invalid_costs(): void
    {
        foreach (['03', '32'] as $cost) {
            DB::connection('convolab_admin_test')->table('User')->delete();
            $userId = (string) Str::uuid();
            $this->insertSourceUser($userId, [
                'email' => "cost-{$cost}@example.com",
                'password' => '$2b$'.$cost.'$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6',
            ]);

            $this->runSync()
                ->expectsOutputToContain("Convo Lab user [{$userId}] has an unsupported password hash.")
                ->assertFailed();
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('admin_user_projections', 0);
    }
}
