<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\DeleteAdminScriptLabCoursesAction;
use App\Domain\Admin\Actions\ListAdminScriptLabCoursesAction;
use App\Domain\Admin\Data\CreateAdminScriptLabCourseData;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AdminScriptLabCourseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_routes_require_the_dedicated_admin_proxy_scopes(): void
    {
        $courseId = (string) Str::uuid();

        $this->getJson('/api/convolab/admin/script-lab/courses')->assertUnauthorized();
        $this->withToken($this->proxyToken(['admin:write']))
            ->getJson('/api/convolab/admin/script-lab/courses/'.$courseId)
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($this->proxyToken(['admin:read']))
            ->postJson('/api/convolab/admin/script-lab/courses', [])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken($this->proxyToken(['admin:read']))
            ->deleteJson('/api/convolab/admin/script-lab/courses', [])
            ->assertForbidden();
    }

    public function test_create_builds_a_learning_owned_test_course_and_inline_episode(): void
    {
        $actor = $this->projectedUser();

        $response = $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', strtoupper($actor->convolab_id))
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => '  Ordering Food  ',
                'sourceText' => '  A short restaurant dialogue.  ',
                'jlptLevel' => 'N4',
            ])
            ->assertOk()
            ->assertJsonPath('isTestCourse', true);

        $course = ContentCourse::query()->findOrFail($response->json('courseId'));
        $episode = ContentEpisode::query()->sole();
        $link = ContentEpisodeCourse::query()->sole();

        $this->assertSame($actor->id, $course->user_id);
        $this->assertSame($actor->convolab_id, $course->convolab_user_id);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
        $this->assertSame('[TEST] Ordering Food', $course->title);
        $this->assertSame('Test course for Script Lab: Ordering Food', $course->description);
        $this->assertTrue($course->is_test_course);
        $this->assertSame(ContentCourseDefaults::NARRATOR_VOICE_EN, $course->l1_voice_id);
        $this->assertSame('fishaudio', $course->speaker1_voice_provider);
        $this->assertSame('N4', $course->jlpt_level);
        $this->assertSame('A short restaurant dialogue.', $episode->source_text);
        $this->assertFalse($episode->auto_generate_audio);
        $this->assertSame($episode->id, $link->episode_id);
        $this->assertSame($course->id, $link->convolab_course_id);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $link->source_system);
    }

    public function test_create_can_reuse_only_an_episode_owned_by_the_actor(): void
    {
        $actor = $this->projectedUser();
        $other = $this->projectedUser();
        $ownedEpisode = $this->episode($actor);
        $otherEpisode = $this->episode($other);
        $realEpisode = $this->episode($actor);
        $this->link($this->course($actor, ['is_test_course' => false]), $realEpisode, 0);
        $token = $this->proxyToken(['admin:write']);

        $response = $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => 'Existing Episode',
                'sourceText' => 'Compatibility source text',
                'episodeId' => strtoupper($ownedEpisode->id),
            ])
            ->assertOk();

        $this->assertSame(
            $ownedEpisode->id,
            ContentEpisodeCourse::query()
                ->where('convolab_course_id', $response->json('courseId'))
                ->value('episode_id'),
        );
        $this->assertSame(3, ContentEpisode::query()->count());
        $this->assertSame(
            ContentSourceSystem::LEARNING_OS,
            $ownedEpisode->fresh()->source_system,
        );

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => 'Wrong Owner',
                'sourceText' => 'Compatibility source text',
                'episodeId' => $otherEpisode->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Episode not found');

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => 'Real Course Episode',
                'sourceText' => 'Compatibility source text',
                'episodeId' => $realEpisode->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Episode not found');

        $this->assertSame(ContentSourceSystem::CONVOLAB, $realEpisode->fresh()->source_system);
        $this->assertDatabaseCount('content_courses', 2);
    }

    public function test_create_validates_actor_and_bounded_legacy_inputs_before_writing(): void
    {
        $actor = $this->projectedUser();
        $token = $this->proxyToken(['admin:write']);

        $this->withToken($token)
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => 'Course',
                'sourceText' => 'Source',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => '',
                'sourceText' => '',
                'episodeId' => 'not-a-uuid',
                'targetLanguage' => 'fr',
                'nativeLanguage' => 'de',
                'jlptLevel' => 'N0',
                'maxDurationMinutes' => 121,
                'speaker1Gender' => 'unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title',
                'sourceText',
                'episodeId',
                'targetLanguage',
                'nativeLanguage',
                'jlptLevel',
                'maxDurationMinutes',
                'speaker1Gender',
            ]);

        $this->assertDatabaseCount('content_courses', 0);
        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_create_normalizes_whitespace_without_global_trim_middleware(): void
    {
        $actor = $this->projectedUser();

        $this->withoutMiddleware(TrimStrings::class)
            ->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id)
            ->postJson('/api/convolab/admin/script-lab/courses', [
                'title' => '   ',
                'sourceText' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'sourceText']);

        $this->assertDatabaseCount('content_courses', 0);
    }

    public function test_create_data_enforces_the_direct_caller_contract(): void
    {
        $episodeId = (string) Str::uuid();
        $minimum = CreateAdminScriptLabCourseData::fromInput([
            'title' => ' Minimum ',
            'sourceText' => ' Source ',
            'episodeId' => strtoupper($episodeId),
            'maxDurationMinutes' => '+1',
        ]);
        $maximum = CreateAdminScriptLabCourseData::fromInput([
            'title' => 'Maximum',
            'sourceText' => 'Source',
            'maxDurationMinutes' => 120,
        ]);

        $this->assertSame('Minimum', $minimum->title);
        $this->assertSame('Source', $minimum->sourceText);
        $this->assertSame($episodeId, $minimum->episodeId);
        $this->assertSame(1, $minimum->maxDurationMinutes);
        $this->assertSame(120, $maximum->maxDurationMinutes);

        foreach ([
            ['title' => ' ', 'sourceText' => 'Source'],
            ['title' => 'Course', 'sourceText' => ' '],
            ['title' => 'Course', 'sourceText' => 'Source', 'episodeId' => []],
            ['title' => 'Course', 'sourceText' => 'Source', 'targetLanguage' => 'fr'],
            ['title' => 'Course', 'sourceText' => 'Source', 'jlptLevel' => 'N0'],
            ['title' => 'Course', 'sourceText' => 'Source', 'maxDurationMinutes' => 0],
            ['title' => 'Course', 'sourceText' => 'Source', 'maxDurationMinutes' => 121],
            ['title' => 'Course', 'sourceText' => 'Source', 'speaker1Gender' => 'unknown'],
        ] as $invalid) {
            try {
                CreateAdminScriptLabCourseData::fromInput($invalid);
                $this->fail('Expected invalid direct Script Lab course input to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_list_is_global_test_only_stably_ordered_and_preserves_pipeline_shape(): void
    {
        $firstUser = $this->projectedUser();
        $secondUser = $this->projectedUser();
        $older = $this->course($firstUser, [
            'title' => '[TEST] Exchanges',
            'script_json' => ['_pipelineStage' => 'exchanges', '_exchanges' => []],
            'created_at' => now()->subMinute(),
        ]);
        $newer = $this->course($secondUser, [
            'title' => '[TEST] Script',
            'script_json' => ['_pipelineStage' => 'script', '_exchanges' => [['textL2' => '猫']]],
            'script_units_json' => [['type' => 'L2', 'text' => '猫']],
            'audio_url' => '/audio/test.mp3',
            'created_at' => now(),
        ]);
        $this->course($firstUser, ['is_test_course' => false, 'title' => 'Real course']);

        $this->withToken($this->proxyToken(['admin:read']))
            ->getJson('/api/convolab/admin/script-lab/courses')
            ->assertOk()
            ->assertExactJson(['courses' => [
                [
                    'id' => $newer->id,
                    'title' => '[TEST] Script',
                    'status' => 'draft',
                    'createdAt' => $newer->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'hasExchanges' => true,
                    'hasScript' => true,
                    'hasAudio' => true,
                ],
                [
                    'id' => $older->id,
                    'title' => '[TEST] Exchanges',
                    'status' => 'draft',
                    'createdAt' => $older->created_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'hasExchanges' => true,
                    'hasScript' => false,
                    'hasAudio' => false,
                ],
            ]]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(ListAdminScriptLabCoursesAction::class)->handle();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(1, $queries, 'Script Lab list must stay one query as course count grows.');
    }

    public function test_show_returns_first_episode_and_legacy_pipeline_detail(): void
    {
        $user = $this->projectedUser();
        $course = $this->course($user, [
            'description' => 'Details',
            'jlpt_level' => 'N3',
            'script_json' => [
                '_pipelineStage' => 'script',
                '_exchanges' => [['textL2' => '一番']],
                '_scriptUnits' => [['type' => 'L2', 'text' => 'old']],
            ],
            'script_units_json' => [['type' => 'L2', 'text' => 'canonical']],
        ]);
        $later = $this->episode($user, ['source_text' => 'Later']);
        $first = $this->episode($user, ['source_text' => 'First']);
        $this->link($course, $later, 1);
        $this->link($course, $first, 0);

        $this->withToken($this->proxyToken(['admin:read']))
            ->getJson('/api/convolab/admin/script-lab/courses/'.strtoupper($course->id))
            ->assertOk()
            ->assertJsonStructure([
                'id', 'title', 'description', 'status', 'createdAt', 'jlptLevel',
                'hasExchanges', 'hasScript', 'hasAudio', 'audioUrl', 'sourceText',
                'exchanges', 'scriptUnits',
            ])
            ->assertJsonPath('id', $course->id)
            ->assertJsonPath('description', 'Details')
            ->assertJsonPath('jlptLevel', 'N3')
            ->assertJsonPath('sourceText', 'First')
            ->assertJsonPath('hasExchanges', true)
            ->assertJsonPath('hasScript', true)
            ->assertJsonPath('hasAudio', false)
            ->assertJsonFragment(['audioUrl' => null])
            ->assertJsonPath('exchanges.0.textL2', '一番')
            ->assertJsonPath('scriptUnits.0.text', 'canonical');
    }

    public function test_show_hides_non_test_and_missing_courses(): void
    {
        $user = $this->projectedUser();
        $realCourse = $this->course($user, ['is_test_course' => false]);
        $token = $this->proxyToken(['admin:read']);

        $this->withToken($token)
            ->getJson('/api/convolab/admin/script-lab/courses/'.$realCourse->id)
            ->assertNotFound()
            ->assertJsonPath('message', 'Test course not found');
        $this->withToken($token)
            ->getJson('/api/convolab/admin/script-lab/courses/'.Str::uuid())
            ->assertNotFound();
    }

    public function test_bulk_delete_removes_only_existing_test_courses_and_writes_tombstones(): void
    {
        $user = $this->projectedUser();
        $first = $this->course($user);
        $second = $this->course($user);
        $episode = $this->episode($user);
        $this->link($first, $episode, 0);

        $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', $user->convolab_id)
            ->deleteJson('/api/convolab/admin/script-lab/courses', [
                'courseIds' => [strtoupper($first->id), $second->id, (string) Str::uuid()],
            ])
            ->assertOk()
            ->assertExactJson(['deleted' => 2]);

        $this->assertDatabaseCount('content_courses', 0);
        $this->assertDatabaseCount('content_episode_courses', 0);
        $this->assertDatabaseHas('content_episodes', ['id' => $episode->id]);
        $this->assertDatabaseHas('content_course_tombstones', [
            'course_id' => $first->id,
            'user_id' => $user->id,
            'convolab_user_id' => $user->convolab_id,
        ]);
        $this->assertDatabaseHas('content_course_tombstones', ['course_id' => $second->id]);
    }

    public function test_bulk_delete_batches_tombstones_and_rejects_cross_owner_reuse(): void
    {
        $user = $this->projectedUser();
        $courses = collect([
            $this->course($user),
            $this->course($user),
            $this->course($user),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(DeleteAdminScriptLabCoursesAction::class)->handle($courses->pluck('id')->all());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $tombstoneQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'content_course_tombstones'),
        ));
        $this->assertCount(2, $tombstoneQueries);
        $this->assertDatabaseCount('content_course_tombstones', 3);

        $course = $this->course($user);
        $other = $this->projectedUser();
        ContentCourseTombstone::query()->forceCreate([
            'course_id' => $course->id,
            'user_id' => $other->id,
            'convolab_user_id' => $other->convolab_id,
            'deleted_at' => now()->subDay(),
        ]);

        $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', $user->convolab_id)
            ->deleteJson('/api/convolab/admin/script-lab/courses', [
                'courseIds' => [$course->id],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('content_courses', ['id' => $course->id]);
        $this->assertDatabaseHas('content_course_tombstones', [
            'course_id' => $course->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_bulk_delete_rejects_mixed_non_test_ids_atomically(): void
    {
        $user = $this->projectedUser();
        $testCourse = $this->course($user);
        $realCourse = $this->course($user, ['is_test_course' => false]);

        $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', $user->convolab_id)
            ->deleteJson('/api/convolab/admin/script-lab/courses', [
                'courseIds' => [$testCourse->id, $realCourse->id],
            ])
            ->assertBadRequest()
            ->assertJsonPath(
                'message',
                'Cannot delete non-test courses via Script Lab. Use the standard admin interface.',
            );

        $this->assertDatabaseHas('content_courses', ['id' => $testCourse->id]);
        $this->assertDatabaseHas('content_courses', ['id' => $realCourse->id]);
        $this->assertDatabaseCount('content_course_tombstones', 0);
    }

    public function test_bulk_delete_validates_nonempty_distinct_bounded_uuid_list(): void
    {
        $actor = $this->projectedUser();
        $id = (string) Str::uuid();
        $request = $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', $actor->convolab_id);

        $request->deleteJson('/api/convolab/admin/script-lab/courses', ['courseIds' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('courseIds');
        $request->deleteJson('/api/convolab/admin/script-lab/courses', [
            'courseIds' => [$id, strtoupper($id)],
        ])->assertUnprocessable()->assertJsonValidationErrors('courseIds.1');
        $request->deleteJson('/api/convolab/admin/script-lab/courses', [
            'courseIds' => array_fill(0, 101, 'not-a-uuid'),
        ])->assertUnprocessable()->assertJsonValidationErrors('courseIds');
    }

    public function test_bulk_delete_action_rejects_invalid_ids_before_querying(): void
    {
        foreach ([
            [['not-a-uuid'], 'Course ID must be a UUID.'],
            [[[]], 'Script Lab course IDs must be UUID strings.'],
            [[], 'Script Lab course IDs must contain 1 to 100 UUIDs.'],
        ] as [$ids, $message]) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                app(DeleteAdminScriptLabCoursesAction::class)->handle($ids);
                $this->fail('Expected invalid direct Script Lab course IDs to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            $this->assertSame([], $queries);
        }
    }

    public function test_course_mutation_routes_use_separate_named_limiters(): void
    {
        $expected = [
            'POST' => AdminMutationRateLimiter::SCRIPT_LAB_COURSE_CREATE,
            'DELETE' => AdminMutationRateLimiter::SCRIPT_LAB_COURSE_DELETE,
        ];

        foreach ($expected as $method => $limiter) {
            $route = collect(Route::getRoutes())->first(
                fn ($route): bool => $route->uri() === 'api/convolab/admin/script-lab/courses'
                    && in_array($method, $route->methods(), true),
            );
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }

        $this->assertCount(2, array_unique($expected));
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'proxy@example.com'],
            ['name' => 'Proxy', 'password' => 'unused'],
        );

        return $user->createToken('convolab-proxy', $abilities)->plainTextToken;
    }

    private function projectedUser(): User
    {
        $convoLabId = (string) Str::uuid();
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);
        DB::table('admin_user_projections')->insert([
            'convolab_id' => $convoLabId,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => 'admin',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'onboarding_completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->refresh();
    }

    /** @param array<string, mixed> $overrides */
    private function course(User $user, array $overrides = []): ContentCourse
    {
        return ContentCourse::query()->forceCreate(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $user->convolab_id,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => '[TEST] Course',
            'description' => 'Test course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => true,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => ContentCourseDefaults::NARRATOR_VOICE_EN,
            'l1_voice_provider' => 'fishaudio',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'speaker1_voice_id' => 'fishaudio:speaker-1',
            'speaker1_voice_provider' => 'fishaudio',
            'speaker2_voice_id' => 'fishaudio:speaker-2',
            'speaker2_voice_provider' => 'fishaudio',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function episode(User $user, array $overrides = []): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $user->convolab_id,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Episode',
            'source_text' => 'Source',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'auto_generate_audio' => false,
            'status' => 'draft',
            'is_sample_content' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function link(ContentCourse $course, ContentEpisode $episode, int $order): void
    {
        ContentEpisodeCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'convolab_course_id' => $course->id,
            'sort_order' => $order,
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
    }
}
