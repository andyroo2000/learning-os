<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\CreateContentCourseAction;
use App\Domain\Content\Actions\GenerateContentCourseScriptAction;
use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GenerateContentCourseScriptActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.content_model' => 'course-test',
            'services.openai.content_reasoning_effort' => 'low',
        ]);
    }

    public function test_it_generates_and_atomically_persists_a_course_script_and_core_items(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        Http::fake(['openai.test/v1/responses' => Http::response([
            'output_text' => json_encode($this->providerPayload(), JSON_THROW_ON_ERROR),
        ])]);

        $generated = app(GenerateContentCourseScriptAction::class)->handle(
            $user->id,
            strtoupper($sourceUserId),
            strtoupper($course->id),
        );

        $this->assertNotNull($generated);
        $this->assertSame('script', $generated->script_json['_pipelineStage']);
        $this->assertCount(4, $generated->script_units_json);
        $this->assertGreaterThan(0, $generated->approx_duration_seconds);
        $this->assertSame(1, $generated->generation_revision);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $generated->source_system);
        $this->assertDatabaseHas('content_course_core_items', [
            'course_id' => $course->id,
            'text_l2' => '猫',
            'reading_l2' => 'ねこ',
            'translation_l1' => 'cat',
            'source_unit_index' => 0,
        ]);
        $this->assertSame(
            $course->courseEpisodes()->sole()->episode_id,
            $generated->coreItems()->sole()->source_episode_id,
        );
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://openai.test/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $data['model'] === 'course-test'
                && $data['text']['format']['type'] === 'json_object'
                && str_contains($data['input'][1]['content'][0]['text'], 'Inline source text.');
        });
    }

    public function test_provider_failure_preserves_the_previous_script_and_core_items(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        $course->script_json = ['old' => true];
        $course->script_units_json = [['type' => 'pause', 'seconds' => 1]];
        $course->approx_duration_seconds = 1;
        $course->save();
        ContentCourseCoreItem::query()->forceCreate([
            'id' => (string) Str::uuid(), 'course_id' => $course->id,
            'text_l2' => '旧', 'reading_l2' => null, 'translation_l1' => 'old',
            'complexity_score' => 0.1, 'source_episode_id' => null,
            'source_sentence_id' => null, 'source_unit_index' => null, 'components' => null,
        ]);
        Http::fake(['openai.test/v1/responses' => Http::response(['error' => ['message' => 'down']], 503)]);

        try {
            app(GenerateContentCourseScriptAction::class)->handle($user->id, $sourceUserId, $course->id);
            $this->fail('Expected Course generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('OpenAI failed to generate Course content.', $exception->getMessage());
        }

        $course->refresh();
        $this->assertSame(['old' => true], $course->script_json);
        $this->assertSame(1, $course->approx_duration_seconds);
        $this->assertSame('旧', $course->coreItems()->sole()->text_l2);
    }

    public function test_a_newer_generation_revision_rejects_a_stale_provider_result(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        Http::fake(function () use ($course) {
            DB::table('content_courses')->where('id', $course->id)->increment('generation_revision');

            return Http::response([
                'output_text' => json_encode($this->providerPayload(), JSON_THROW_ON_ERROR),
            ]);
        });

        try {
            app(GenerateContentCourseScriptAction::class)->handle($user->id, $sourceUserId, $course->id);
            $this->fail('Expected stale Course generation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Course changed while its script was being generated.', $exception->getMessage());
        }

        $course->refresh();
        $this->assertSame(2, $course->generation_revision);
        $this->assertNull($course->script_json);
        $this->assertDatabaseCount('content_course_core_items', 0);
    }

    public function test_a_stale_generation_attempt_returns_before_calling_the_provider(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        $course->forceFill([
            'status' => 'generating',
            'generation_attempt' => 4,
        ])->save();
        $revision = (int) $course->generation_revision;
        Http::fake();

        $generated = app(GenerateContentCourseScriptAction::class)->handle(
            $user->id,
            $sourceUserId,
            $course->id,
            3,
        );

        $this->assertNull($generated);
        $this->assertSame($revision, $course->fresh()->generation_revision);
        $this->assertNull($course->fresh()->script_json);
        $this->assertDatabaseCount('content_course_core_items', 0);
        Http::assertNothingSent();
    }

    public function test_reset_during_provider_work_rejects_the_stale_script_result(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        $course->forceFill([
            'status' => 'generating',
            'generation_attempt' => 4,
        ])->save();
        Http::fake(function () use ($course) {
            DB::table('content_courses')->where('id', $course->id)->update([
                'status' => 'draft',
                'generation_attempt' => 5,
            ]);

            return Http::response([
                'output_text' => json_encode($this->providerPayload(), JSON_THROW_ON_ERROR),
            ]);
        });

        try {
            app(GenerateContentCourseScriptAction::class)->handle(
                $user->id,
                $sourceUserId,
                $course->id,
                4,
            );
            $this->fail('Expected the reset Course generation result to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Course changed while its script was being generated.', $exception->getMessage());
        }

        $course->refresh();
        $this->assertSame('draft', $course->status);
        $this->assertSame(5, $course->generation_attempt);
        $this->assertNull($course->script_json);
        $this->assertDatabaseCount('content_course_core_items', 0);
    }

    public function test_missing_and_cross_owner_courses_are_hidden_without_provider_calls(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        Http::fake();

        $this->assertNull(app(GenerateContentCourseScriptAction::class)->handle(
            User::factory()->create()->id,
            $sourceUserId,
            $course->id,
        ));
        $this->assertNull(app(GenerateContentCourseScriptAction::class)->handle(
            $user->id,
            $sourceUserId,
            (string) Str::uuid(),
        ));

        Http::assertNothingSent();
    }

    public function test_generation_revision_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new ContentCourse)->fill(['generation_revision' => 99]);
    }

    public function test_provider_cannot_select_a_voice_outside_the_course_snapshot(): void
    {
        [$user, $sourceUserId, $course] = $this->course();
        $payload = $this->providerPayload();
        $payload['scriptUnits'][3]['voiceId'] = 'fishaudio:unknown';
        Http::fake(['openai.test/v1/responses' => Http::response([
            'output_text' => json_encode($payload, JSON_THROW_ON_ERROR),
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Course generator returned an unknown speaker voice ID.');

        app(GenerateContentCourseScriptAction::class)->handle($user->id, $sourceUserId, $course->id);
    }

    /** @return array{User, string, ContentCourse} */
    private function course(): array
    {
        $user = User::factory()->create();
        $sourceUserId = (string) Str::uuid();
        $result = app(CreateContentCourseAction::class)->handle(CreateContentCourseData::fromInput(
            $user->id,
            $sourceUserId,
            [
                'title' => 'Generated Course',
                'description' => 'A test Course.',
                'sourceText' => 'Inline source text.',
                'nativeLanguage' => 'en',
                'targetLanguage' => 'ja',
                'speaker1VoiceId' => 'fishaudio:aki',
                'speaker2VoiceId' => 'fishaudio:yui',
            ],
        ));
        $course = $result->course;
        $this->assertNotNull($course);

        return [$user, $sourceUserId, $course];
    }

    /** @return array<string, mixed> */
    private function providerPayload(): array
    {
        return [
            'exchanges' => [[
                'speakerName' => 'Aki', 'speakerVoiceId' => 'fishaudio:aki',
                'textL2' => '猫です。', 'readingL2' => '猫[ねこ]です。',
                'translationL1' => 'It is a cat.',
                'vocabularyItems' => [[
                    'textL2' => '猫', 'readingL2' => 'ねこ', 'translationL1' => 'cat',
                    'complexityScore' => 0.25, 'components' => [['text' => '猫']],
                ]],
            ]],
            'scriptUnits' => [
                ['type' => 'marker', 'label' => 'Start'],
                ['type' => 'narration_L1', 'text' => 'Listen.', 'voiceId' => 'fishaudio:ac934b39586e475b83f3277cd97b5cd4'],
                ['type' => 'pause', 'seconds' => 2],
                [
                    'type' => 'L2', 'text' => '猫です。', 'reading' => '猫[ねこ]です。',
                    'translation' => 'It is a cat.', 'voiceId' => 'fishaudio:aki', 'speed' => 1,
                ],
            ],
        ];
    }
}
