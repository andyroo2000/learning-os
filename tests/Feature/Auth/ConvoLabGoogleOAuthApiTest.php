<?php

namespace Tests\Feature\Auth;

use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Auth\Actions\ClaimConvoLabGoogleInviteAction;
use App\Domain\Auth\Actions\DisconnectConvoLabGoogleIdentityAction;
use App\Domain\Auth\Actions\ResolveConvoLabGoogleIdentityAction;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabGoogleOAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_identity_schema_stores_no_google_credentials_and_uses_portable_named_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('convolab_oauth_identities', [
            'id',
            'user_id',
            'provider',
            'provider_id',
            'access_granted_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('convolab_oauth_identities', 'access_token'));
        $this->assertFalse(Schema::hasColumn('convolab_oauth_identities', 'refresh_token'));

        $indexes = collect(Schema::getIndexes('convolab_oauth_identities'));
        $this->assertTrue($indexes->firstWhere('name', 'convolab_oauth_provider_identity_unique')['unique']);
        $this->assertTrue($indexes->firstWhere('name', 'convolab_oauth_user_provider_unique')['unique']);
    }

    public function test_retired_proxy_oauth_routes_are_not_exposed(): void
    {
        $this->postJson('/api/convolab/auth/google', [
            'providerId' => 'subject',
            'email' => 'ada@example.com',
            'emailVerified' => true,
            'name' => 'Ada',
        ])->assertMethodNotAllowed();

        $this->postJson('/api/convolab/auth/google/invite', ['inviteCode' => 'WELCOME'])
            ->assertNotFound();
    }

    public function test_new_and_retried_google_identity_is_verified_invite_gated_and_idempotent(): void
    {
        $action = app(ResolveConvoLabGoogleIdentityAction::class);
        $first = $action->handle(
            'subject-1',
            'new@example.com',
            'First Name',
            'https://example.com/avatar.png',
            true,
        );
        $second = $action->handle(
            'subject-1',
            'changed@example.com',
            'Changed Name',
            null,
            true,
        );

        $this->assertTrue($first->created);
        $this->assertTrue($first->requiresInvite);
        $this->assertFalse($second->created);
        $this->assertTrue($second->requiresInvite);
        $this->assertSame($first->account->convolab_id, $second->account->convolab_id);
        $this->assertSame('new@example.com', $second->account->email);
        $this->assertTrue($second->account->email_verified);
        $this->assertDatabaseCount('convolab_oauth_identities', 1);
        $this->assertDatabaseCount('admin_user_projections', 1);
    }

    public function test_identity_resolution_rejects_unverified_and_invalid_provider_profiles(): void
    {
        $action = app(ResolveConvoLabGoogleIdentityAction::class);

        try {
            $action->handle('subject', 'unverified@example.com', 'User', null, false);
            $this->fail('Expected an unverified Google email to be rejected.');
        } catch (ConvoLabOAuthException $exception) {
            $this->assertSame('unverified_email', $exception->reason());
            $this->assertSame(422, $exception->status());
        }

        foreach ([
            ['', 'ada@example.com', 'Ada', null],
            [str_repeat('a', 256), 'ada@example.com', 'Ada', null],
            ['subject', 'not-an-email', 'Ada', null],
            ['subject', 'ada@example.com', '', null],
            ['subject', 'ada@example.com', 'Ada', 'javascript:alert(1)'],
            ['subject', 'ada@example.com', 'Ada', 'ftp://example.com/avatar.png'],
        ] as [$providerId, $email, $name, $avatarUrl]) {
            try {
                $action->handle($providerId, $email, $name, $avatarUrl, true);
                $this->fail('Expected an invalid Google profile to be rejected.');
            } catch (ConvoLabOAuthException $exception) {
                $this->assertSame('invalid_profile', $exception->reason());
                $this->assertSame(422, $exception->status());
            }
        }

        $this->assertDatabaseCount('convolab_oauth_identities', 0);
    }

    public function test_existing_verified_account_links_without_overwriting_profile_or_password(): void
    {
        $account = $this->projectedUser(['email' => 'existing@example.com']);
        $user = User::query()->findOrFail($account['user_id']);
        $originalPassword = $user->password;

        $result = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'existing-subject',
            'EXISTING@example.com',
            'Google Name',
            null,
            true,
        );

        $this->assertFalse($result->created);
        $this->assertFalse($result->requiresInvite);
        $this->assertSame($account['convolab_id'], $result->account->convolab_id);
        $this->assertSame('Source User', $result->account->name);
        $this->assertSame($originalPassword, $user->refresh()->password);
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $user->id,
            'provider_id' => 'existing-subject',
        ]);
    }

    public function test_existing_unverified_account_and_different_subject_cannot_be_taken_over(): void
    {
        $unverified = $this->projectedUser([
            'email' => 'unverified@example.com',
            'email_verified' => false,
            'email_verified_at' => null,
        ]);
        $unverifiedUser = User::query()->findOrFail($unverified['user_id']);
        $passwordHash = Hash::make('existing-password');
        $unverifiedUser->forceFill(['convolab_password_hash' => $passwordHash])->save();

        try {
            app(ResolveConvoLabGoogleIdentityAction::class)->handle(
                'subject',
                'unverified@example.com',
                'User',
                null,
                true,
            );
            $this->fail('Expected an unverified account to reject Google linking.');
        } catch (ConvoLabOAuthException $exception) {
            $this->assertSame('existing_account_unverified', $exception->reason());
            $this->assertSame(409, $exception->status());
        }

        $linked = $this->projectedUser(['email' => 'linked@example.com']);
        $action = app(ResolveConvoLabGoogleIdentityAction::class);
        $action->handle('original-subject', 'linked@example.com', 'Linked', null, true);

        try {
            $action->handle('different-subject', 'linked@example.com', 'Linked', null, true);
            $this->fail('Expected a different Google subject to be rejected.');
        } catch (ConvoLabOAuthException $exception) {
            $this->assertSame('identity_already_connected', $exception->reason());
            $this->assertSame(409, $exception->status());
        }

        $this->assertSame($passwordHash, $unverifiedUser->refresh()->convolab_password_hash);
        $this->assertDatabaseHas('convolab_oauth_identities', [
            'user_id' => $linked['user_id'],
            'provider_id' => 'original-subject',
        ]);
        $this->assertDatabaseCount('convolab_oauth_identities', 1);
    }

    public function test_invite_claim_is_locked_retry_safe_and_rejects_reuse(): void
    {
        $result = app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'claim-subject',
            'claim@example.com',
            'Claim User',
            null,
            true,
        );
        $invite = $this->invite('WELCOME123');
        $action = app(ClaimConvoLabGoogleInviteAction::class);

        $claimed = $action->handle($result->account->convolab_id, 'WELCOME123');
        $retried = $action->handle($result->account->convolab_id, 'WELCOME123');

        $this->assertSame($result->account->convolab_id, $claimed->convolab_id);
        $this->assertSame($claimed->convolab_id, $retried->convolab_id);
        $this->assertSame($result->account->user_id, $invite->refresh()->used_by);

        $another = $this->invite('ANOTHER123');
        try {
            $action->handle($result->account->convolab_id, 'ANOTHER123');
            $this->fail('Expected a second invite claim to be rejected.');
        } catch (ConvoLabOAuthException $exception) {
            $this->assertSame('invite_already_claimed', $exception->reason());
            $this->assertSame(409, $exception->status());
        }
        $this->assertNull($another->refresh()->used_by);
    }

    public function test_disconnect_uses_the_authenticated_browser_identity(): void
    {
        $account = $this->projectedUser(['email' => 'linked@example.com']);
        app(ResolveConvoLabGoogleIdentityAction::class)->handle(
            'linked-subject',
            'linked@example.com',
            'Linked',
            null,
            true,
        );
        $user = User::query()->findOrFail($account['user_id']);

        $this->asConvoLabBrowser($user, convoLabUserId: $account['convolab_id'])
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->deleteJson('/api/convolab/auth/google')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Google account disconnected',
            ]);

        $this->assertDatabaseMissing('convolab_oauth_identities', ['user_id' => $user->id]);
        $this->assertDatabaseHas('admin_user_projections', ['convolab_id' => $account['convolab_id']]);

        $this->deleteJson('/api/convolab/auth/google')
            ->assertNotFound()
            ->assertJsonPath('reason', 'identity_not_found');
    }

    public function test_direct_actions_hide_malformed_and_unknown_account_ids(): void
    {
        foreach (['not-a-uuid', (string) Str::uuid()] as $id) {
            try {
                app(DisconnectConvoLabGoogleIdentityAction::class)->handle($id);
                $this->fail('Expected a hidden not-found response.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('convolab_oauth_identities', 0);
            }

            try {
                app(ClaimConvoLabGoogleInviteAction::class)->handle($id, 'WELCOME');
                $this->fail('Expected a hidden not-found response.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('admin_invite_codes', 0);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function projectedUser(array $attributes = []): array
    {
        $convoLabId = (string) Str::uuid();
        $projection = array_merge([
            'convolab_id' => $convoLabId,
            'email' => 'user-'.Str::lower(Str::random(8)).'@example.com',
            'name' => 'Source User',
            'role' => 'user',
            'email_verified' => true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'source_system' => 'convolab',
            'avatar_source_system' => 'convolab',
        ], $attributes);
        $user = User::factory()->create([
            'email' => strtolower($projection['email']),
            'email_verified_at' => $projection['email_verified_at'],
        ]);
        $user->forceFill([
            'convolab_id' => $convoLabId,
            'convolab_email_normalized' => strtolower($projection['email']),
        ])->save();
        $projection['user_id'] = $user->id;
        DB::table('admin_user_projections')->insert($projection);

        return $projection;
    }

    private function invite(string $code): AdminInviteCode
    {
        $invite = new AdminInviteCode;
        $invite->id = (string) Str::uuid();
        $invite->code = $code;
        $invite->created_at = now();
        $invite->source_system = 'convolab';
        $invite->save();

        return $invite;
    }
}
