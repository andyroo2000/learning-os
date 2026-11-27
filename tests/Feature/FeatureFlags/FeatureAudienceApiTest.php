<?php

namespace Tests\Feature\FeatureFlags;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Actions\GetEffectiveFeatureFlagsAction;
use App\Domain\FeatureFlags\Actions\SaveFeatureAudienceAction;
use App\Domain\FeatureFlags\Models\FeatureFlag;
use App\Domain\FeatureFlags\Support\AudienceInput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FeatureAudienceApiTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/convolab/admin/feature-access';

    private function learner(string $name = 'Maya Chen'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->convoLabProjectionFor($user, (string) Str::uuid());

        return $user;
    }

    private function group(User $user): array
    {
        return $this->postJson(self::ROOT.'/groups', ['name' => 'Beta testers', 'version' => 0, 'userIds' => [$user->id]])
            ->assertCreated()->json();
    }

    public function test_audience_requires_admin_browser_and_search_never_leaks_users(): void
    {
        $this->getJson(self::ROOT.'/users')->assertUnauthorized();
        $this->asConvoLabBrowser($this->learner())->getJson(self::ROOT.'/users')->assertForbidden();
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', ['version' => 0, 'userIds' => [], 'groupIds' => []])->assertForbidden();
        $this->postJson(self::ROOT.'/groups', ['name' => 'No', 'version' => 0, 'userIds' => []])->assertForbidden();
    }

    public function test_direct_and_group_grants_are_additive_and_revoke_on_next_read(): void
    {
        $direct = $this->learner();
        $member = $this->learner('Ken Sato');
        $other = $this->learner('Alex');
        $this->asConvoLabAdminBrowser(User::factory()->create());
        $group = $this->group($member);
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', [
            'version' => 0, 'userIds' => [(string) $direct->id], 'groupIds' => [$group['id']],
        ])->assertOk()->assertJsonPath('version', 1)->assertJsonPath('users.0.id', (string) $direct->id);
        $effective = app(GetEffectiveFeatureFlagsAction::class);
        $this->assertTrue($effective->handle($direct->id)['readingsEnabled']);
        $this->assertTrue($effective->handle($member->id)['readingsEnabled']);
        $this->assertFalse($effective->handle($other->id)['readingsEnabled']);
        $this->putJson(self::ROOT.'/groups/'.$group['id'], ['name' => 'Beta testers', 'version' => 1, 'userIds' => []])->assertOk();
        $this->assertFalse($effective->handle($member->id)['readingsEnabled']);
        $this->assertTrue($effective->handle($direct->id)['readingsEnabled']);
        FeatureFlag::query()->update(['readingsEnabled' => true]);
        $this->assertTrue($effective->handle($other->id)['readingsEnabled']);
        $this->getJson('/api/feature-flags')->assertOk()->assertJsonPath('readingsEnabled', true);
    }

    public function test_group_changes_apply_to_every_assigned_feature_and_overlap_survives(): void
    {
        $user = $this->learner();
        $this->asConvoLabAdminBrowser(User::factory()->create());
        $group = $this->group($user);
        foreach (['readingsEnabled', 'learnEnabled'] as $feature) {
            $this->putJson(self::ROOT.'/audiences/'.$feature, ['version' => 0, 'userIds' => [$user->id], 'groupIds' => [$group['id']]])->assertOk();
        }
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', ['version' => 1, 'userIds' => [], 'groupIds' => [$group['id']]])->assertOk();
        $this->assertTrue(app(GetEffectiveFeatureFlagsAction::class)->handle($user->id)['readingsEnabled']);
        $this->getJson(self::ROOT.'/groups')->assertOk()->assertJsonCount(2, 'groups.0.features');
        $this->putJson(self::ROOT.'/groups/'.$group['id'], ['name' => 'Testers', 'version' => 1, 'userIds' => []])->assertOk();
        $this->assertFalse(app(GetEffectiveFeatureFlagsAction::class)->handle($user->id)['readingsEnabled']);
        $this->assertTrue(app(GetEffectiveFeatureFlagsAction::class)->handle($user->id)['learnEnabled']);
    }

    public function test_stale_versions_do_not_overwrite_audience_or_membership(): void
    {
        $user = $this->learner();
        $this->asConvoLabAdminBrowser(User::factory()->create());
        $group = $this->group($user);
        $payload = ['version' => 0, 'userIds' => [$user->id], 'groupIds' => []];
        $this->putJson(self::ROOT.'/audiences/learnEnabled', $payload)->assertOk();
        $this->putJson(self::ROOT.'/audiences/learnEnabled', [...$payload, 'userIds' => []])->assertConflict();
        $this->putJson(self::ROOT.'/groups/'.$group['id'], ['name' => 'Changed', 'version' => 0, 'userIds' => []])->assertConflict();
        $this->assertDatabaseHas('feature_audience_users', ['feature' => 'learnEnabled', 'user_id' => $user->id]);
        $this->assertDatabaseHas('feature_group_members', ['group_id' => $group['id'], 'user_id' => $user->id]);
    }

    public function test_search_filters_case_insensitively_escapes_wildcards_and_returns_canonical_ids(): void
    {
        $user = $this->learner('Maya 100%');
        $this->learner('Someone else');
        $this->asConvoLabAdminBrowser(User::factory()->create());
        $group = $this->group($user);
        $this->getJson(self::ROOT.'/users?'.http_build_query(['search' => 'MAYA 100%', 'role' => 'user', 'groupId' => ' '.strtoupper($group['id']).' ']))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('users.0.id', (string) $user->id)->assertJsonPath('users.0.groupIds.0', $group['id']);
        $this->getJson(self::ROOT.'/users?search=missing')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::ROOT.'/users?search[]=bad')->assertUnprocessable();
    }

    public function test_invalid_payloads_and_duplicate_group_names_leave_state_unchanged(): void
    {
        $user = $this->learner();
        $this->asConvoLabAdminBrowser(User::factory()->create());
        $this->group($user);
        $this->postJson(self::ROOT.'/groups', ['name' => ' BETA TESTERS ', 'version' => 0, 'userIds' => []])->assertUnprocessable();
        $this->postJson(self::ROOT.'/groups', ['name' => ' ', 'version' => 0, 'userIds' => []])->assertUnprocessable();
        $this->putJson(self::ROOT.'/audiences/journalEnabled', ['version' => 0, 'userIds' => [], 'groupIds' => []])->assertNotFound();
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', ['version' => 0, 'userIds' => ['invalid'], 'groupIds' => []])->assertUnprocessable();
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', ['version' => 0, 'userIds' => [$user->id, (string) $user->id], 'groupIds' => []])->assertUnprocessable();
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', ['version' => 0, 'userIds' => [[$user->id]], 'groupIds' => []])->assertUnprocessable();
        $this->putJson(self::ROOT.'/audiences/readingsEnabled', ['version' => 0, 'userIds' => [], 'groupIds' => [['invalid']]])->assertUnprocessable();
        $this->assertDatabaseCount('feature_access_groups', 1);
        $this->assertDatabaseCount('feature_audience_users', 0);
    }

    public function test_admin_bearer_tokens_cannot_search_or_mutate_audiences(): void
    {
        $admin = $this->learner('Admin');
        AdminUserProjection::where('user_id', $admin->id)->update(['role' => 'admin']);
        $token = $admin->createToken('native', ['*'])->plainTextToken;
        $this->withToken($token)->getJson(self::ROOT.'/users')->assertForbidden();
        $this->withToken($token)->postJson(self::ROOT.'/groups', ['name' => 'Denied', 'version' => 0, 'userIds' => []])->assertForbidden();
        $this->assertDatabaseCount('feature_access_groups', 0);
    }

    public function test_native_availability_reflects_a_direct_grant(): void
    {
        $user = $this->learner();
        app(SaveFeatureAudienceAction::class)->handle('readingsEnabled', [
            'version' => 0, 'userIds' => [$user->id], 'groupIds' => [],
        ]);
        $this->signIn($user);
        $this->getJson('/api/convolab/feature-availability')->assertOk()
            ->assertJsonPath('readingsEnabled', true)->assertJsonPath('learnEnabled', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_search_pages_have_stable_order_and_do_not_include_unprojected_users(): void
    {
        for ($index = 0; $index < 27; $index++) {
            $this->learner('Identical name');
        }
        User::factory()->create(['name' => 'Identical name']);
        $this->asConvoLabAdminBrowser(User::factory()->create());
        $first = $this->getJson(self::ROOT.'/users?search=Identical&page=1')->assertOk()->assertJsonPath('total', 27)->assertJsonCount(25, 'users')->json('users');
        $second = $this->getJson(self::ROOT.'/users?search=Identical&page=2')->assertOk()->assertJsonCount(2, 'users')->json('users');
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($second, 'id')));
    }

    public function test_deleted_reference_after_validation_rejects_and_rolls_back_the_save(): void
    {
        $user = $this->learner();
        $deleted = false;
        DB::listen(function ($query) use ($user, &$deleted): void {
            $validating = str_contains($query->sql, 'aggregate') && str_contains($query->sql, 'admin_user_projections');
            if (! $deleted && $validating) {
                $deleted = true;
                DB::table('users')->where('id', $user->id)->delete();
            }
        });
        try {
            app(SaveFeatureAudienceAction::class)->handle('readingsEnabled', [
                'version' => 0, 'userIds' => [$user->id], 'groupIds' => [],
            ]);
            $this->fail('The missing reference must reject the save.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('userIds', $error->errors());
        }
        $this->assertTrue($deleted);
        $this->assertDatabaseCount('feature_audience_users', 0);
        $this->assertDatabaseHas('feature_audiences', ['feature' => 'readingsEnabled', 'version' => 0]);
    }

    public function test_global_and_admin_access_do_not_query_audience_memberships(): void
    {
        $user = $this->learner();
        $resolver = app(GetEffectiveFeatureFlagsAction::class);
        $resolver->handle($user->id);
        FeatureFlag::query()->update(['learnEnabled' => true]);
        DB::enableQueryLog();
        $this->assertTrue($resolver->enabled($user->id, 'learnEnabled'));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        AdminUserProjection::where('user_id', $user->id)->update(['role' => 'admin']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(array_fill_keys(AudienceInput::FEATURES, true), $resolver->handle($user->id));
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_audience_seeds_match_every_supported_feature(): void
    {
        $this->assertEqualsCanonicalizing(AudienceInput::FEATURES, DB::table('feature_audiences')->pluck('feature')->all());
    }

    public function test_member_validation_is_batched_for_a_large_selection(): void
    {
        $ids = [];
        for ($index = 0; $index < 30; $index++) {
            $ids[] = $this->learner()->id;
        }
        $this->asConvoLabAdminBrowser(User::factory()->create());
        DB::enableQueryLog();
        $this->postJson(self::ROOT.'/groups', ['name' => 'Thirty members', 'version' => 0, 'userIds' => $ids])
            ->assertCreated()->assertJsonCount(30, 'users');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(25, $queries, 'Membership validation must not issue a query per selected user.');
    }
}
