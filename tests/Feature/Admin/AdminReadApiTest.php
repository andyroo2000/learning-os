<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\GetAdminStatsAction;
use App\Domain\Admin\Actions\ShowAdminUserAction;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_admin_reads_reject_untrusted_bearer_tokens_and_missing_proxy_scope(): void
    {
        $this->getJson('/api/convolab/admin/stats')->assertUnauthorized();

        $ordinaryToken = User::factory()->create()
            ->createToken('mobile', ['admin:read'])
            ->plainTextToken;
        $this->withToken($ordinaryToken)
            ->getJson('/api/convolab/admin/stats')
            ->assertForbidden();

        $wildcardToken = User::factory()->create(['email' => 'wildcard@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
        $this->withToken($wildcardToken)
            ->getJson('/api/convolab/admin/stats')
            ->assertForbidden();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        $this->withToken($this->proxyToken(['study:read']))
            ->getJson('/api/convolab/admin/stats')
            ->assertForbidden();

        config()->set('services.convolab.proxy_user_email', 'different@example.com');
        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/stats')
            ->assertForbidden();
    }

    public function test_stats_count_only_the_convolab_projection_and_source_content(): void
    {
        $sourceUser = $this->projectedUser(['email' => 'ada@example.com']);
        User::factory()->create(['email' => 'learning@example.com']);
        $this->insertEpisode($sourceUser, ContentSourceSystem::CONVOLAB);
        $this->insertEpisode($sourceUser, ContentSourceSystem::LEARNING_OS);
        $this->insertCourse($sourceUser, ContentSourceSystem::CONVOLAB);
        $this->insertCourse($sourceUser, ContentSourceSystem::LEARNING_OS);

        $usedInvite = $this->insertInvite($sourceUser);
        $this->insertInvite();

        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/stats')
            ->assertOk()
            ->assertExactJson([
                'users' => 1,
                'episodes' => 1,
                'courses' => 1,
                'inviteCodes' => [
                    'total' => 2,
                    'used' => 1,
                    'available' => 1,
                ],
            ]);

        $this->assertNotNull($usedInvite->convolab_used_by);
    }

    public function test_stats_return_zero_invite_counts_for_an_empty_projection(): void
    {
        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/stats')
            ->assertOk()
            ->assertJsonPath('inviteCodes.total', 0)
            ->assertJsonPath('inviteCodes.used', 0)
            ->assertJsonPath('inviteCodes.available', 0);
    }

    public function test_stats_use_one_database_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(GetAdminStatsAction::class)->handle();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $queries);
    }

    public function test_users_return_the_legacy_contract_with_stable_pagination_and_counts(): void
    {
        $older = $this->projectedUser([
            'email' => 'older@example.com',
            'name' => 'Older User',
            'created_at' => '2026-07-20 10:00:00.123',
            'updated_at' => '2026-07-20 11:00:00.456',
        ]);
        $newer = $this->projectedUser([
            'email' => 'newer@example.com',
            'name' => 'Newer User',
            'display_name' => 'New Display',
            'avatar_color' => 'teal',
            'avatar_url' => 'https://example.com/avatar.png',
            'role' => 'admin',
            'created_at' => '2026-07-21 10:00:00.123',
            'updated_at' => '2026-07-21 11:00:00.456',
        ]);
        User::factory()->create(['email' => 'canonical-only@example.com']);
        $this->insertEpisode($newer, ContentSourceSystem::CONVOLAB);
        $this->insertEpisode($newer, ContentSourceSystem::LEARNING_OS);
        $this->insertCourse($newer, ContentSourceSystem::CONVOLAB);
        $this->insertCourse($newer, ContentSourceSystem::LEARNING_OS);

        $response = $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/users?limit=1&page=1')
            ->assertOk()
            ->assertExactJson([
                'users' => [[
                    'id' => $newer->convolab_id,
                    'email' => 'newer@example.com',
                    'name' => 'Newer User',
                    'displayName' => 'New Display',
                    'avatarColor' => 'teal',
                    'avatarUrl' => 'https://example.com/avatar.png',
                    'role' => 'admin',
                    'createdAt' => '2026-07-21T10:00:00.123Z',
                    'updatedAt' => '2026-07-21T11:00:00.456Z',
                    '_count' => ['episodes' => 1, 'courses' => 1],
                ]],
                'pagination' => ['page' => 1, 'limit' => 1, 'total' => 2, 'pages' => 2],
            ]);

        $response->assertJsonMissing(['email' => 'canonical-only@example.com']);
        $this->assertNotSame($older->convolab_id, $response->json('users.0.id'));
    }

    public function test_user_search_is_case_insensitive_and_treats_like_wildcards_literally(): void
    {
        $literal = $this->projectedUser([
            'email' => 'literal@example.com',
            'display_name' => 'Progress 100%_Done',
        ]);
        $this->projectedUser([
            'email' => 'wildcard@example.com',
            'display_name' => 'Progress 100X-Done',
        ]);

        $query = http_build_query(['search' => '100%_done']);
        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/users?'.$query)
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('users.0.id', $literal->convolab_id);
    }

    public function test_user_list_normalizes_blank_search_and_validates_bounded_pagination(): void
    {
        $this->projectedUser(['email' => 'ada@example.com']);
        $token = $this->proxyToken();

        $this->withToken($token)
            ->getJson('/api/convolab/admin/users?search=%20%20')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        foreach (['page=0', 'page=1.5', 'limit=0', 'limit=101', 'limit=-1'] as $query) {
            $field = str_starts_with($query, 'page=') ? 'page' : 'limit';
            $this->withToken($token)
                ->getJson('/api/convolab/admin/users?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->withToken($token)
            ->getJson('/api/convolab/admin/users?search='.str_repeat('a', 201))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');

        foreach (['page%5B%5D=1' => 'page', 'limit%5B%5D=10' => 'limit', 'search%5B%5D=ada' => 'search'] as $query => $field) {
            $this->withToken($token)
                ->getJson('/api/convolab/admin/users?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_user_search_normalization_does_not_depend_on_global_trim_middleware(): void
    {
        $user = $this->projectedUser(['email' => 'ada@example.com']);

        $this->withoutMiddleware(TrimStrings::class)
            ->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/users?'.http_build_query(['search' => " \tADA@EXAMPLE.COM\n "]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('users.0.id', $user->convolab_id);
    }

    public function test_user_info_accepts_a_normalized_uuid_and_hides_canonical_ids(): void
    {
        $user = $this->projectedUser([
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'display_name' => 'Ada L',
            'role' => 'admin',
            'avatar_color' => 'violet',
            'avatar_url' => null,
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'onboarding_completed' => true,
        ]);

        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/users/'.strtoupper($user->convolab_id).'/info')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->convolab_id,
                'email' => 'ada@example.com',
                'name' => 'Ada',
                'displayName' => 'Ada L',
                'role' => 'admin',
                'avatarColor' => 'violet',
                'avatarUrl' => null,
                'preferredStudyLanguage' => 'ja',
                'preferredNativeLanguage' => 'en',
                'onboardingCompleted' => true,
            ]);

        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/users/'.Str::uuid().'/info')
            ->assertNotFound();
        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/users/not-a-uuid/info')
            ->assertNotFound();
    }

    public function test_user_info_action_rejects_malformed_uuids_before_querying_postgres(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(ShowAdminUserAction::class)->handle('not-a-uuid');
            $this->fail('Expected a model-not-found exception.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(AdminUserProjection::class, $e->getModel());
            $this->assertSame([], $e->getIds());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame([], $queries);
    }

    public function test_invite_codes_return_nested_users_null_relations_and_stable_order(): void
    {
        $user = $this->projectedUser(['email' => 'ada@example.com', 'name' => 'Ada']);
        $older = $this->insertInvite(null, ['created_at' => '2026-07-20 10:00:00.123']);
        $newer = $this->insertInvite($user, [
            'used_at' => '2026-07-21 10:30:00.456',
            'created_at' => '2026-07-21 10:00:00.123',
        ]);

        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/invite-codes')
            ->assertOk()
            ->assertHeader('X-Pagination-Page', '1')
            ->assertHeader('X-Pagination-Limit', '100')
            ->assertHeader('X-Pagination-Total', '2')
            ->assertHeader('X-Pagination-Pages', '1')
            ->assertExactJson([
                [
                    'id' => $newer->id,
                    'code' => $newer->code,
                    'usedBy' => $user->convolab_id,
                    'usedAt' => '2026-07-21T10:30:00.456Z',
                    'createdAt' => '2026-07-21T10:00:00.123Z',
                    'user' => [
                        'id' => $user->convolab_id,
                        'email' => 'ada@example.com',
                        'name' => 'Ada',
                    ],
                ],
                [
                    'id' => $older->id,
                    'code' => $older->code,
                    'usedBy' => null,
                    'usedAt' => null,
                    'createdAt' => '2026-07-20T10:00:00.123Z',
                    'user' => null,
                ],
            ]);
    }

    public function test_invite_code_pagination_is_bounded_and_preserves_the_array_contract(): void
    {
        $oldest = $this->insertInvite(null, ['created_at' => '2026-07-19 10:00:00.123']);
        $middle = $this->insertInvite(null, ['created_at' => '2026-07-20 10:00:00.123']);
        $newest = $this->insertInvite(null, ['created_at' => '2026-07-21 10:00:00.123']);
        $token = $this->proxyToken();

        $this->withToken($token)
            ->getJson('/api/convolab/admin/invite-codes?limit=2&page=1')
            ->assertOk()
            ->assertHeader('X-Pagination-Total', '3')
            ->assertHeader('X-Pagination-Pages', '2')
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $newest->id)
            ->assertJsonPath('1.id', $middle->id);

        $this->withToken($token)
            ->getJson('/api/convolab/admin/invite-codes?limit=2&page=2')
            ->assertOk()
            ->assertHeader('X-Pagination-Page', '2')
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $oldest->id);

        foreach (['page=0' => 'page', 'limit=101' => 'limit', 'limit%5B%5D=10' => 'limit'] as $query => $field) {
            $this->withToken($token)
                ->getJson('/api/convolab/admin/invite-codes?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_projection_fields_are_not_mass_assignable(): void
    {
        $user = new User([
            'convolab_id' => (string) Str::uuid(),
        ]);

        $this->assertArrayNotHasKey('convolab_id', $user->getAttributes());

        $this->assertSame([], (new AdminInviteCode)->getFillable());
        $this->assertSame(['*'], (new AdminInviteCode)->getGuarded());
        $this->assertSame([], (new AdminUserProjection)->getFillable());
        $this->assertSame(['*'], (new AdminUserProjection)->getGuarded());
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities = ['admin:read']): string
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
        DB::table('users')->where('id', $user->id)->update([
            'convolab_id' => $convoLabId,
        ]);
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

    private function insertEpisode(User $user, string $sourceSystem): void
    {
        DB::table('content_episodes')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $user->convolab_id,
            'source_system' => $sourceSystem,
            'title' => 'Episode',
            'source_text' => 'Source',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCourse(User $user, string $sourceSystem): void
    {
        DB::table('content_courses')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $user->convolab_id,
            'source_system' => $sourceSystem,
            'title' => 'Course',
            'native_language' => 'en',
            'target_language' => 'ja',
            'l1_voice_id' => 'voice',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function insertInvite(?User $user = null, array $attributes = []): AdminInviteCode
    {
        $id = (string) Str::uuid();
        DB::table('admin_invite_codes')->insert(array_merge([
            'id' => $id,
            'code' => strtoupper(Str::random(8)),
            'used_by' => $user?->id,
            'convolab_used_by' => $user?->convolab_id,
            'used_at' => $user === null ? null : now(),
            'created_at' => now(),
        ], $attributes));

        return AdminInviteCode::query()->findOrFail($id);
    }
}
