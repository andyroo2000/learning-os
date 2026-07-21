<?php

namespace Tests\Feature\Auth;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Actions\UpdateConvoLabCurrentUserAction;
use App\Domain\Auth\Data\UpdateConvoLabProfileData;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateConvoLabCurrentUserActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_rejects_malformed_identity_before_starting_profile_work(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(UpdateConvoLabCurrentUserAction::class)->handle(
            'not-a-uuid',
            UpdateConvoLabProfileData::fromValidated(['displayName' => 'Ada']),
        );
    }

    public function test_action_rejects_an_empty_direct_caller_update(): void
    {
        $account = $this->projectedUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one profile field is required.');

        app(UpdateConvoLabCurrentUserAction::class)->handle(
            $account->convolab_id,
            UpdateConvoLabProfileData::fromValidated([]),
        );
    }

    public function test_first_onboarding_completion_copies_and_remaps_sample_content_atomically(): void
    {
        $account = $this->projectedUser();
        $template = $this->sampleContentGraph();

        $updated = app(UpdateConvoLabCurrentUserAction::class)->handle(
            strtoupper($account->convolab_id),
            UpdateConvoLabProfileData::fromValidated([
                'displayName' => 'Ada',
                'proficiencyLevel' => 'N5',
                'onboardingCompleted' => true,
            ]),
        );

        $this->assertTrue($updated->onboarding_completed);
        $this->assertSame('Ada', $updated->display_name);
        $this->assertSame(ConvoLabAccountSource::LEARNING_OS, $updated->source_system);

        $episode = ContentEpisode::query()
            ->where('user_id', $account->user_id)
            ->where('is_sample_content', true)
            ->sole();
        $this->assertNotSame($template['episode']->id, $episode->id);
        $this->assertSame($account->convolab_id, $episode->convolab_user_id);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->source_system);
        $this->assertSame($template['episode']->title, $episode->title);
        $this->assertSame('https://example.com/episode.mp3', $episode->audio_url);

        $dialogue = $episode->dialogue()->sole();
        $speaker = $dialogue->speakers()->sole();
        $sentence = $dialogue->sentences()->sole();
        $this->assertNotSame($template['dialogue']->id, $dialogue->id);
        $this->assertNotSame($template['speaker']->id, $speaker->id);
        $this->assertNotSame($template['sentence']->id, $sentence->id);
        $this->assertSame($dialogue->id, $speaker->dialogue_id);
        $this->assertSame($dialogue->id, $sentence->dialogue_id);
        $this->assertSame($speaker->id, $sentence->speaker_id);
        $this->assertSame('https://example.com/sentence.mp3', $sentence->audio_url);

        $course = ContentCourse::query()
            ->where('user_id', $account->user_id)
            ->where('is_sample_content', true)
            ->sole();
        $link = $course->courseEpisodes()->sole();
        $coreItem = $course->coreItems()->sole();
        $this->assertNotSame($template['course']->id, $course->id);
        $this->assertSame($account->convolab_id, $course->convolab_user_id);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
        $this->assertSame('https://example.com/course.mp3', $course->audio_url);
        $this->assertSame($episode->id, $link->episode_id);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $link->source_system);
        $this->assertSame($episode->id, $coreItem->source_episode_id);
        $this->assertSame($sentence->id, $coreItem->source_sentence_id);

        $this->assertDatabaseHas('content_episodes', ['id' => $template['episode']->id]);
        $this->assertDatabaseHas('content_courses', ['id' => $template['course']->id]);
    }

    public function test_repeated_updates_do_not_duplicate_sample_content(): void
    {
        $account = $this->projectedUser();
        $this->sampleContentGraph();
        $action = app(UpdateConvoLabCurrentUserAction::class);

        $action->handle(
            $account->convolab_id,
            UpdateConvoLabProfileData::fromValidated([
                'proficiencyLevel' => 'N5',
                'onboardingCompleted' => true,
            ]),
        );
        $action->handle(
            $account->convolab_id,
            UpdateConvoLabProfileData::fromValidated([
                'onboardingCompleted' => true,
                'proficiencyLevel' => 'N5',
                'seenSampleContentGuide' => true,
            ]),
        );

        $this->assertSame(2, ContentEpisode::query()->count());
        $this->assertSame(2, ContentCourse::query()->count());
        $this->assertSame(2, ContentDialogue::query()->count());
        $this->assertSame(2, ContentSpeaker::query()->count());
        $this->assertSame(2, ContentSentence::query()->count());
        $this->assertSame(2, ContentEpisodeCourse::query()->count());
        $this->assertSame(2, ContentCourseCoreItem::query()->count());
        $this->assertTrue($account->refresh()->seen_sample_content_guide);
    }

    public function test_duplicate_source_templates_are_copied_only_once(): void
    {
        $account = $this->projectedUser();
        $this->sampleContentGraph();
        $this->sampleContentGraph();

        app(UpdateConvoLabCurrentUserAction::class)->handle(
            $account->convolab_id,
            UpdateConvoLabProfileData::fromValidated([
                'proficiencyLevel' => 'N5',
                'onboardingCompleted' => true,
            ]),
        );

        $this->assertSame(1, ContentEpisode::query()->where('user_id', $account->user_id)->count());
        $this->assertSame(1, ContentCourse::query()->where('user_id', $account->user_id)->count());
    }

    public function test_another_users_copies_never_become_onboarding_templates(): void
    {
        $firstAccount = $this->projectedUser(['email' => 'first@example.com']);
        $template = $this->sampleContentGraph();
        $action = app(UpdateConvoLabCurrentUserAction::class);
        $action->handle(
            $firstAccount->convolab_id,
            UpdateConvoLabProfileData::fromValidated([
                'proficiencyLevel' => 'N5',
                'onboardingCompleted' => true,
            ]),
        );

        $template['course']->delete();
        $template['episode']->delete();
        $secondAccount = $this->projectedUser(['email' => 'second@example.com']);
        $action->handle(
            $secondAccount->convolab_id,
            UpdateConvoLabProfileData::fromValidated([
                'proficiencyLevel' => 'N5',
                'onboardingCompleted' => true,
            ]),
        );

        $this->assertSame(0, ContentEpisode::query()->where('user_id', $secondAccount->user_id)->count());
        $this->assertSame(0, ContentCourse::query()->where('user_id', $secondAccount->user_id)->count());
        $this->assertSame(1, ContentEpisode::query()->where('user_id', $firstAccount->user_id)->count());
        $this->assertSame(1, ContentCourse::query()->where('user_id', $firstAccount->user_id)->count());
    }

    public function test_onboarding_requires_an_explicit_proficiency_level_at_the_action_boundary(): void
    {
        $account = $this->projectedUser(['proficiency_level' => 'beginner']);

        try {
            app(UpdateConvoLabCurrentUserAction::class)->handle(
                $account->convolab_id,
                UpdateConvoLabProfileData::fromValidated(['onboardingCompleted' => true]),
            );
            $this->fail('Expected onboarding without a proficiency level to fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'proficiencyLevel is required when completing onboarding.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse($account->refresh()->onboarding_completed);
    }

    public function test_unmappable_curated_course_links_are_logged_and_skipped(): void
    {
        Log::spy();
        $account = $this->projectedUser();
        $template = $this->sampleContentGraph();
        $unmatched = $this->sampleContentGraph();
        $unmatched['course']->delete();
        $unmatched['episode']->jlpt_level = 'N4';
        $unmatched['episode']->save();
        $unmatched['speaker']->proficiency = 'N4';
        $unmatched['speaker']->save();
        $template['link']->episode_id = $unmatched['episode']->id;
        $template['link']->save();

        app(UpdateConvoLabCurrentUserAction::class)->handle(
            $account->convolab_id,
            UpdateConvoLabProfileData::fromValidated([
                'proficiencyLevel' => 'N5',
                'onboardingCompleted' => true,
            ]),
        );

        $this->assertSame(1, ContentEpisode::query()->where('user_id', $account->user_id)->count());
        $this->assertSame(0, ContentCourse::query()->where('user_id', $account->user_id)->count());
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Skipping ConvoLab sample course because an episode link could not be mapped.',
                ['course_id' => $template['course']->id, 'episode_id' => $unmatched['episode']->id],
            );
    }

    public function test_sample_copy_failure_rolls_back_profile_and_content_changes(): void
    {
        $account = $this->projectedUser(['display_name' => 'Before']);
        $this->sampleContentGraph();
        DB::table('content_source_locks')->delete();

        try {
            app(UpdateConvoLabCurrentUserAction::class)->handle(
                $account->convolab_id,
                UpdateConvoLabProfileData::fromValidated([
                    'displayName' => 'After',
                    'proficiencyLevel' => 'N5',
                    'onboardingCompleted' => true,
                ]),
            );
            $this->fail('Expected the missing source lock to abort onboarding.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The Convo Lab content source lock is missing.', $exception->getMessage());
        }

        $account->refresh();
        $this->assertSame('Before', $account->display_name);
        $this->assertFalse($account->onboarding_completed);
        $this->assertSame(ConvoLabAccountSource::CONVOLAB, $account->source_system);
        $this->assertSame(0, ContentEpisode::query()->where('user_id', $account->user_id)->count());
        $this->assertSame(0, ContentCourse::query()->where('user_id', $account->user_id)->count());
    }

    /** @param array<string, mixed> $attributes */
    private function projectedUser(array $attributes = []): AdminUserProjection
    {
        $user = User::factory()->create();
        $convoLabId = (string) Str::uuid();
        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);
        DB::table('admin_user_projections')->insert(array_merge([
            'convolab_id' => $convoLabId,
            'user_id' => $user->id,
            'email' => 'user@example.com',
            'name' => 'Source User',
            'display_name' => null,
            'avatar_color' => 'indigo',
            'avatar_url' => null,
            'role' => 'user',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'proficiency_level' => 'N5',
            'onboarding_completed' => false,
            'seen_sample_content_guide' => false,
            'seen_custom_content_guide' => false,
            'email_verified' => true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'source_system' => ConvoLabAccountSource::CONVOLAB,
        ], $attributes));

        return AdminUserProjection::query()->findOrFail($convoLabId);
    }

    /** @return array<string, object> */
    private function sampleContentGraph(): array
    {
        $owner = User::factory()->create();
        $ownerConvoLabId = (string) Str::uuid();
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'convolab_user_id' => $ownerConvoLabId,
            'title' => 'Ordering Coffee',
            'source_text' => 'Order coffee at a cafe.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'jlpt_level' => 'N5',
            'auto_generate_audio' => false,
            'status' => 'ready',
            'is_sample_content' => true,
            'audio_url' => 'https://example.com/episode.mp3',
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
        ]);
        $speaker = ContentSpeaker::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $dialogue->id,
            'name' => 'Customer',
            'voice_id' => 'voice-1',
            'proficiency' => 'N5',
            'tone' => 'friendly',
        ]);
        $sentence = ContentSentence::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $dialogue->id,
            'speaker_id' => $speaker->id,
            'sort_order' => 0,
            'text' => 'コーヒーをください。',
            'translation' => 'Coffee, please.',
            'metadata' => ['reading' => 'コーヒーをください。'],
            'audio_url' => 'https://example.com/sentence.mp3',
            'selected' => true,
        ]);
        $course = ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'convolab_user_id' => $ownerConvoLabId,
            'title' => 'Cafe Basics',
            'description' => 'A starter cafe course.',
            'status' => 'ready',
            'is_sample_content' => true,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 15,
            'l1_voice_id' => 'voice-en',
            'jlpt_level' => 'N5',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
            'audio_url' => 'https://example.com/course.mp3',
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $link = ContentEpisodeCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'convolab_course_id' => $course->id,
            'sort_order' => 0,
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $coreItem = ContentCourseCoreItem::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'course_id' => $course->id,
            'text_l2' => 'コーヒーをください。',
            'reading_l2' => 'コーヒーをください。',
            'translation_l1' => 'Coffee, please.',
            'complexity_score' => 1.0,
            'source_episode_id' => $episode->id,
            'source_sentence_id' => $sentence->id,
            'source_unit_index' => 0,
            'components' => ['noun' => 'coffee'],
        ]);

        return compact('episode', 'dialogue', 'speaker', 'sentence', 'course', 'link', 'coreItem');
    }
}
