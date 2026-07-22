<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\CreateAdminInviteCodeAction;
use App\Domain\Admin\Actions\DeleteAdminInviteCodeAction;
use App\Domain\Admin\Actions\DeleteAdminUserAction;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMutationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_admin_writes_require_the_dedicated_proxy_identity_and_write_scope(): void
    {
        $actor = (string) Str::uuid();
        $target = (string) Str::uuid();

        $this->withHeader('X-Convo-Lab-User-Id', $actor)
            ->deleteJson("/api/convolab/admin/users/{$target}")
            ->assertUnauthorized();

        $ordinary = User::factory()->create()
            ->createToken('mobile', ['admin:write'])
            ->plainTextToken;
        $this->withToken($ordinary)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->deleteJson("/api/convolab/admin/users/{$target}")
            ->assertForbidden();

        $this->withToken($this->proxyToken(['admin:read']))
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->postJson('/api/convolab/admin/invite-codes')
            ->assertForbidden();

        $wildcard = User::factory()->create(['email' => 'wildcard@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
        $this->withToken($wildcard)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->postJson('/api/convolab/admin/invite-codes')
            ->assertForbidden();
    }

    public function test_admin_writes_validate_and_normalize_the_actor_header(): void
    {
        $token = $this->proxyToken();

        foreach ([null, '', 'not-a-uuid'] as $actor) {
            $request = $this->withToken($token);
            if ($actor !== null) {
                $request = $request->withHeader('X-Convo-Lab-User-Id', $actor);
            }

            $request->postJson('/api/convolab/admin/invite-codes')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('actorConvoLabUserId');
        }

        $actor = strtoupper((string) Str::uuid());
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->postJson('/api/convolab/admin/invite-codes', ['customCode' => 'VALID123'])
            ->assertOk();
    }

    public function test_it_creates_custom_and_generated_invite_codes_with_learning_os_ownership(): void
    {
        $actor = (string) Str::uuid();
        $token = $this->proxyToken();

        $response = $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->postJson('/api/convolab/admin/invite-codes', ['customCode' => 'WELCOME9'])
            ->assertOk()
            ->assertJsonPath('code', 'WELCOME9')
            ->assertJsonPath('usedBy', null)
            ->assertJsonPath('usedAt', null);

        $this->assertSame(['id', 'code', 'usedBy', 'usedAt', 'createdAt'], array_keys($response->json()));
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $response->json('id'),
            'code' => 'WELCOME9',
            'source_system' => ConvoLabAccountSource::LEARNING_OS,
        ]);

        $generated = $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->postJson('/api/convolab/admin/invite-codes')
            ->assertOk()
            ->json('code');

        $this->assertIsString($generated);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{8}$/', $generated);
    }

    public function test_custom_invite_validation_is_independent_of_global_trim_middleware(): void
    {
        $actor = (string) Str::uuid();
        $token = $this->proxyToken();

        foreach ([['array'], 'short', 'ABC-123', str_repeat('A', 21), ' VALID1 '] as $code) {
            $this->withoutMiddleware(TrimStrings::class)
                ->withToken($token)
                ->withHeader('X-Convo-Lab-User-Id', $actor)
                ->postJson('/api/convolab/admin/invite-codes', ['customCode' => $code])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('customCode');
        }

        $this->assertDatabaseCount('admin_invite_codes', 0);
    }

    public function test_duplicate_custom_invite_returns_the_legacy_error_without_overwriting(): void
    {
        $this->insertInvite(['code' => 'DUPLICATE']);

        $this->withToken($this->proxyToken())
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson('/api/convolab/admin/invite-codes', ['customCode' => 'DUPLICATE'])
            ->assertBadRequest()
            ->assertExactJson(['message' => 'This code already exists']);

        $this->assertDatabaseCount('admin_invite_codes', 1);
    }

    public function test_generated_invite_collisions_are_retried_without_blame_the_admin(): void
    {
        $this->insertInvite(['code' => 'COLLIDE1']);
        $codes = ['COLLIDE1', 'FRESH123'];
        $action = new CreateAdminInviteCodeAction(
            static function () use (&$codes): string {
                return array_shift($codes) ?? throw new \LogicException('Unexpected generation call.');
            },
        );

        $invite = $action->handle(null);

        $this->assertSame('FRESH123', $invite->code);
        $this->assertSame([], $codes);
        $this->assertDatabaseCount('admin_invite_codes', 2);
    }

    public function test_generated_invite_collision_retries_are_bounded(): void
    {
        $this->insertInvite(['code' => 'COLLIDE1']);
        $calls = 0;
        $action = new CreateAdminInviteCodeAction(
            static function () use (&$calls): string {
                $calls++;

                return 'COLLIDE1';
            },
        );

        try {
            $action->handle(null);
            $this->fail('Expected generated invite attempts to be exhausted.');
        } catch (AdminMutationException $exception) {
            $this->assertSame('Unable to generate invite code', $exception->getMessage());
            $this->assertSame(503, $exception->status());
        }

        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('admin_invite_codes', 1);
    }

    public function test_it_deletes_an_unused_invite_and_rejects_used_or_missing_invites(): void
    {
        $actor = (string) Str::uuid();
        $token = $this->proxyToken();
        $unused = $this->insertInvite();

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->deleteJson('/api/convolab/admin/invite-codes/'.strtoupper($unused->id))
            ->assertOk()
            ->assertExactJson(['message' => 'Invite code deleted successfully']);
        $this->assertDatabaseMissing('admin_invite_codes', ['id' => $unused->id]);
        $this->assertDatabaseHas('admin_invite_code_tombstones', [
            'invite_code_id' => $unused->id,
        ]);

        $user = $this->projectedUser();
        $used = $this->insertInvite([
            'used_by' => $user->id,
            'convolab_used_by' => $user->convolab_id,
            'used_at' => now(),
        ]);
        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->deleteJson("/api/convolab/admin/invite-codes/{$used->id}")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Cannot delete used invite codes']);
        $this->assertDatabaseMissing('admin_invite_code_tombstones', [
            'invite_code_id' => $used->id,
        ]);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->deleteJson('/api/convolab/admin/invite-codes/'.Str::uuid())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Invite code not found']);
    }

    public function test_admin_user_deletion_guards_self_and_other_admin_accounts(): void
    {
        $actor = $this->projectedUser();
        $admin = $this->projectedUser(['role' => 'admin']);
        $token = $this->proxyToken();

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->deleteJson("/api/convolab/admin/users/{$actor->convolab_id}")
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Cannot delete your own account']);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->deleteJson("/api/convolab/admin/users/{$admin->convolab_id}")
            ->assertForbidden()
            ->assertExactJson(['message' => 'Cannot delete admin users']);

        $this->assertDatabaseHas('users', ['id' => $actor->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_user_deletion_removes_canonical_state_and_releases_the_invite(): void
    {
        $actor = $this->projectedUser(['role' => 'admin']);
        $target = $this->projectedUser();
        $target->createToken('mobile', ['study:read']);
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $target->email,
            'token' => 'reset-token',
            'created_at' => now(),
        ]);
        $invite = $this->insertInvite([
            'used_by' => $target->id,
            'convolab_used_by' => $target->convolab_id,
            'used_at' => now(),
        ]);

        $this->withToken($this->proxyToken())
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->deleteJson("/api/convolab/admin/users/{$target->convolab_id}")
            ->assertOk()
            ->assertExactJson(['message' => 'User deleted successfully']);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('admin_user_projections', ['convolab_id' => $target->convolab_id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $invite->id,
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'source_system' => ConvoLabAccountSource::LEARNING_OS,
        ]);
    }

    public function test_mutation_routes_use_distinct_named_rate_limiters(): void
    {
        $expected = [
            'api/convolab/admin/users/{convoLabUserId}' => 'convolab-admin-user-delete',
            'api/convolab/admin/invite-codes' => 'convolab-admin-invite-create',
            'api/convolab/admin/invite-codes/{inviteId}' => 'convolab-admin-invite-delete',
        ];

        foreach ($expected as $uri => $limiter) {
            $route = collect(Route::getRoutes())->first(
                fn ($route): bool => $route->uri() === $uri
                    && in_array($uri === 'api/convolab/admin/invite-codes' ? 'POST' : 'DELETE', $route->methods(), true),
            );

            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
    }

    public function test_direct_actions_reject_invalid_state_without_partial_writes(): void
    {
        $actor = $this->projectedUser(['role' => 'admin']);
        $target = $this->projectedUser(['role' => 'admin']);
        $invite = $this->insertInvite(['convolab_used_by' => $target->convolab_id]);

        try {
            app(DeleteAdminUserAction::class)->handle($actor->convolab_id, $target->convolab_id);
            $this->fail('Expected admin deletion to be rejected.');
        } catch (AdminMutationException $exception) {
            $this->assertSame('Cannot delete admin users', $exception->getMessage());
        }
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $invite->id,
            'convolab_used_by' => $target->convolab_id,
        ]);

        $this->expectException(AdminMutationException::class);
        app(DeleteAdminInviteCodeAction::class)->handle($invite->id);
    }

    public function test_direct_invite_creation_enforces_the_same_code_contract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom code must be 6-20 alphanumeric characters');

        app(CreateAdminInviteCodeAction::class)->handle('bad-code');
    }

    public function test_direct_user_deletion_rejects_each_malformed_id_before_querying(): void
    {
        foreach ([
            ['not-a-uuid', (string) Str::uuid()],
            [(string) Str::uuid(), 'not-a-uuid'],
        ] as [$actorId, $targetId]) {
            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                app(DeleteAdminUserAction::class)->handle($actorId, $targetId);
                $this->fail('Expected the malformed ID to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Convo Lab user ID must be a UUID.', $exception->getMessage());
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            $this->assertSame([], $queries);
        }
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities = ['admin:write']): string
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'proxy@example.com'],
            ['name' => 'Proxy', 'password' => 'unused'],
        );

        return $user->createToken('convolab-proxy', $abilities)->plainTextToken;
    }

    /** @param array<string, mixed> $attributes */
    private function projectedUser(array $attributes = []): User
    {
        $convoLabId = (string) Str::uuid();
        $user = User::factory()->create([
            'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
            'name' => $attributes['name'] ?? 'Projected User',
        ]);
        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);
        DB::table('admin_user_projections')->insert(array_merge([
            'convolab_id' => $convoLabId,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'display_name' => null,
            'avatar_color' => null,
            'avatar_url' => null,
            'role' => 'user',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'onboarding_completed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return $user->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function insertInvite(array $attributes = []): AdminInviteCode
    {
        $id = (string) Str::uuid();
        DB::table('admin_invite_codes')->insert(array_merge([
            'id' => $id,
            'code' => strtoupper(Str::random(8)),
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => now(),
        ], $attributes));

        return AdminInviteCode::query()->findOrFail($id);
    }
}
