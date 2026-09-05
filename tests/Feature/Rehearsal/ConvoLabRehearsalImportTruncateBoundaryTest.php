<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConvoLabRehearsalImportTruncateBoundaryTest extends ConvoLabRehearsalImportTestCase
{
    public function test_truncate_explicitly_clears_the_full_user_data_boundary(): void
    {
        $this->seedConvoLabSourceData();
        $existingUser = User::factory()->create();
        $now = now();

        $this->seedCoreBoundaryData($existingUser);
        $this->seedMilestoneData($existingUser, $now);
        $convoLabUserId = $this->seedAdminIdentityData($existingUser, $now);
        $this->seedIntegrationData($existingUser, $now);
        $this->seedVocabularyData($existingUser, $convoLabUserId, $now);
        $this->runTruncatingImport();
        $this->assertFullUserDataBoundaryWasCleared();
    }

    private function seedCoreBoundaryData(User $existingUser): void
    {
        $conceptCard = Card::factory()->create();
        DB::table('card_introduction_cohorts')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $existingUser->id,
            'source_kind' => 'lesson_followup',
            'label' => 'Reset boundary cohort',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('card_learning_concepts')->insert([
            'card_id' => $conceptCard->id,
            'concept_id' => 'n5-vocab-1198550-2120ff50',
            'match_method' => 'exact',
            'match_source' => 'backfill',
            'confidence' => 1,
            'classifier_version' => 'n5-rules-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Course::factory()->for($existingUser)->create();
        $contentCourse = ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $existingUser->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Reset boundary course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => true,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 15,
            'l1_voice_id' => 'fishaudio:0123456789abcdef0123456789abcdef',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AdminCourseLineRendering::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'course_id' => $contentCourse->id,
            'unit_index' => 0,
            'text' => 'Reset this rendering.',
            'speed' => 1,
            'voice_id' => 'fishaudio:0123456789abcdef0123456789abcdef',
            'audio_url' => '/api/convolab/admin/courses/'.$contentCourse->id.'/line-renderings/audio',
            'audio_storage_path' => 'admin/course-lines/reset.mp3',
            'created_at' => now(),
        ]);
        StudyCardDraft::factory()->for($existingUser)->create();
        SyncFeedEntry::factory()->for($existingUser)->create();
    }

    private function seedMilestoneData(User $existingUser, CarbonInterface $now): void
    {
        DB::table('study_milestone_profiles')->insert([
            'user_id' => $existingUser->id,
            'initialized_at' => $now,
        ]);
        DB::table('study_milestones')->insert([
            'user_id' => $existingUser->id,
            'milestone_key' => 'burned100',
            'earned_at' => $now,
            'presented_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('achievement_awards')->insert([
            'user_id' => $existingUser->id,
            'achievement_id' => 'card-muncher.first-nibble',
            'earned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedAdminIdentityData(User $existingUser, CarbonInterface $now): string
    {
        $convoLabUserId = (string) Str::uuid();
        DB::table('admin_user_projections')->insert([
            'convolab_id' => $convoLabUserId,
            'user_id' => $existingUser->id,
            'email' => $existingUser->email,
            'name' => $existingUser->name,
            'display_name' => null,
            'avatar_color' => null,
            'avatar_url' => null,
            'role' => 'user',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'onboarding_completed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_invite_codes')->insert([
            'id' => (string) Str::uuid(),
            'code' => 'RESET123',
            'used_by' => $existingUser->id,
            'convolab_used_by' => $convoLabUserId,
            'used_at' => $now,
            'created_at' => $now,
        ]);
        DB::table('convolab_email_verification_tokens')->insert([
            'user_id' => $existingUser->id,
            'token_hash' => hash('sha256', 'reset-boundary-token'),
            'expires_at' => $now->copy()->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('convolab_oauth_identities')->insert([
            'user_id' => $existingUser->id,
            'provider' => 'google',
            'provider_id' => 'reset-boundary-subject',
            'access_granted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $convoLabUserId;
    }

    private function seedIntegrationData(User $existingUser, CarbonInterface $now): void
    {
        DB::table('japanese_knowledge_profiles')->insert([
            'user_id' => $existingUser->id,
            'knowledge_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('wanikani_connections')->insert([
            'user_id' => $existingUser->id,
            'api_token' => 'encrypted-test-token',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $calendarConnectionId = DB::table('google_calendar_connections')->insertGetId([
            'user_id' => $existingUser->id,
            'provider_account_id' => 'reset-boundary-account',
            'account_email' => $existingUser->email,
            'access_token' => 'encrypted-access-token',
            'scopes' => json_encode(['calendar.readonly'], JSON_THROW_ON_ERROR),
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'connected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('google_calendar_event_mirrors')->insert([
            'google_calendar_connection_id' => $calendarConnectionId,
            'source_key' => hash('sha256', 'reset-boundary-calendar-event'),
            'calendar_id' => 'primary',
            'provider_event_id' => 'reset-boundary-event',
            'status' => 'confirmed',
            'all_day' => false,
            'observed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('google_calendar_connect_intents')->insert([
            'state_hash' => hash('sha256', 'reset-boundary-state'),
            'user_id' => $existingUser->id,
            'completion_target' => 'ios',
            'expires_at' => $now->copy()->addMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_known_kanji')->insert([
            'user_id' => $existingUser->id,
            'character' => '私',
            'manually_added_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedVocabularyData(
        User $existingUser,
        string $convoLabUserId,
        CarbonInterface $now,
    ): void {
        $variantGroupId = strtolower((string) Str::ulid());
        DB::table('study_vocab_variant_groups')->insert([
            'id' => $variantGroupId,
            'user_id' => $existingUser->id,
            'target_word' => '会社',
            'include_learner_context' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('study_vocab_variant_sentences')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $existingUser->id,
            'variant_group_id' => $variantGroupId,
            'ordinal' => 0,
            'sentence_jp' => '会社で働いています。',
            'sentence_en' => 'I work at a company.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('admin_sentence_script_tests')->insert([
            'id' => (string) Str::uuid(),
            'actor_convolab_user_id' => $convoLabUserId,
            'sentence' => 'リセットします。',
            'translation' => 'Reset it.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'jlpt_level' => 'N4',
            'l1_voice_id' => 'fishaudio:ac934b39586e475b83f3277cd97b5cd4',
            'l2_voice_id' => 'fishaudio:0dff3f6860294829b98f8c4501b2cf25',
            'prompt_template' => 'Prompt',
            'units_json' => json_encode([], JSON_THROW_ON_ERROR),
            'raw_response' => '{}',
            'estimated_duration_secs' => 0,
            'parse_error' => null,
            'created_at' => $now,
        ]);
    }

    private function runTruncatingImport(): void
    {
        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);
    }

    private function assertFullUserDataBoundaryWasCleared(): void
    {
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('admin_sentence_script_tests', 0);
        $this->assertDatabaseCount('admin_course_line_renderings', 0);
        $this->assertDatabaseCount('content_courses', 0);
        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertDatabaseCount('study_milestones', 0);
        $this->assertDatabaseCount('achievement_awards', 0);
        $this->assertDatabaseCount('study_milestone_profiles', 0);
        $this->assertDatabaseCount('card_learning_concepts', 0);
        $this->assertDatabaseCount('card_introduction_cohorts', 0);
        $this->assertDatabaseCount('learning_concepts', 1490);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertDatabaseCount('japanese_knowledge_profiles', 0);
        $this->assertDatabaseCount('wanikani_connections', 0);
        $this->assertDatabaseCount('google_calendar_event_mirrors', 0);
        $this->assertDatabaseCount('google_calendar_connections', 0);
        $this->assertDatabaseCount('google_calendar_connect_intents', 0);
        $this->assertDatabaseCount('user_known_kanji', 0);
        $this->assertDatabaseCount('study_vocab_variant_sentences', 0);
        $this->assertDatabaseCount('study_vocab_variant_groups', 0);
        $this->assertDatabaseCount('convolab_email_verification_tokens', 0);
        $this->assertDatabaseCount('convolab_oauth_identities', 0);
        $this->assertDatabaseCount('admin_invite_codes', 0);
        $this->assertDatabaseCount('admin_user_projections', 0);
        $this->assertDatabaseCount('users', 1);
    }
}
