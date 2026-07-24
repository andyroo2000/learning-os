<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ShowStudyOverviewApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    private const CONVOLAB_IMPORT_ID = '98f42a62-8303-410e-ad4d-5a69c55911bb';

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/study/overview')->assertUnauthorized();
    }

    public function test_show_returns_overview_for_the_authenticated_user(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T03:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 2,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
                'introduced_at' => Carbon::parse('2026-06-03T05:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 2,
            ]);

            $this->getJson('/api/study/overview?time_zone=America/New_York')
                ->assertOk()
                ->assertJsonMissingPath('data')
                ->assertJsonPath('newCardsPerDay', 2)
                ->assertJsonPath('newCardsIntroducedToday', 1)
                ->assertJsonPath('newCardsAvailableToday', 1)
                ->assertJsonPath('nextDueAt', '2026-06-05T00:00:00.000000Z')
                ->assertJsonPath('latestImport', null)
                ->assertJsonFragment(['latestImport' => null])
                ->assertJsonPath('totalCards', 3);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_includes_the_authenticated_users_latest_import(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create();
        StudyImportJob::factory()->completed()->for($user)->create([
            'created_at' => Carbon::parse('2026-06-03T12:00:00Z'),
        ]);
        $latestImport = StudyImportJob::factory()->failed()->for($user)->create([
            'convolab_id' => self::CONVOLAB_IMPORT_ID,
            'source_filename' => 'latest.colpkg',
            'error_message' => 'Import failed.',
            'created_at' => Carbon::parse('2026-06-04T12:00:00Z'),
        ]);
        StudyImportJob::factory()->completed()->for(User::factory()->create())->create([
            'created_at' => Carbon::parse('2026-06-05T12:00:00Z'),
        ]);

        $this->getJson('/api/study/overview')
            ->assertOk()
            ->assertJsonPath('latestImport.id', self::CONVOLAB_IMPORT_ID)
            ->assertJsonPath('latestImport.status', StudyImportStatus::Failed->value)
            ->assertJsonPath('latestImport.sourceFilename', 'latest.colpkg')
            ->assertJsonPath('latestImport.errorMessage', 'Import failed.')
            ->assertJsonStructure([
                'latestImport' => [
                    'id',
                    'status',
                    'sourceType',
                    'sourceFilename',
                    'sourceContentType',
                    'sourceSizeBytes',
                    'deckName',
                    'preview',
                    'summary',
                    'errorMessage',
                    'startedAt',
                    'uploadedAt',
                    'uploadExpiresAt',
                    'completedAt',
                    'createdAt',
                    'updatedAt',
                ],
            ]);
    }

    public function test_show_preserves_convolab_timestamp_milliseconds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create();
            $card = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
            $latestImport = StudyImportJob::factory()->completed()->for($user)->create([
                'convolab_id' => self::CONVOLAB_IMPORT_ID,
                'created_at' => Carbon::parse('2026-04-24T17:35:14.108Z'),
            ]);
            // The rehearsal importer bulk-inserts source timestamp strings instead of using Eloquent's date formatter.
            DB::table('cards')->where('id', $card->id)->update([
                'due_at' => '2026-07-03 13:12:56.844',
            ]);
            DB::table('study_import_jobs')->where('id', $latestImport->id)->update([
                'completed_at' => '2026-04-24 17:35:14.108',
            ]);

            $this->getJson('/api/study/overview?time_zone=America/New_York')
                ->assertOk()
                ->assertJsonPath('nextDueAt', '2026-07-03T13:12:56.844000Z')
                ->assertJsonPath('latestImport.completedAt', '2026-04-24T17:35:14.108Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_filters_overview_by_deck_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            $otherDeck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 2,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 2,
            ]);
            $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
                'introduced_at' => Carbon::parse('2026-06-04T11:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
                'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
            ]);

            $this->getJson("/api/study/overview?deck_id={$deck->id}&time_zone=UTC")
                ->assertOk()
                ->assertJsonPath('dueCount', 0)
                ->assertJsonPath('newCount', 2)
                ->assertJsonPath('newCardsIntroducedToday', 1)
                ->assertJsonPath('newCardsAvailableToday', 1)
                ->assertJsonPath('totalCards', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_filters_overview_by_course_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $course = Course::factory()->for($user)->create();
            $deck = $this->deckFor($user, ['course_id' => $course->id]);
            $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
            $outsideCourseDeck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 3,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::Review, [
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::Review, [
                'introduced_at' => Carbon::parse('2026-06-04T11:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);

            $this->getJson("/api/study/overview?courseId={$course->id}&time_zone=UTC")
                ->assertOk()
                ->assertJsonPath('dueCount', 0)
                ->assertJsonPath('reviewCount', 1)
                ->assertJsonPath('newCount', 1)
                ->assertJsonPath('newCardsIntroducedToday', 1)
                ->assertJsonPath('newCardsAvailableToday', 1)
                ->assertJsonPath('totalCards', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_reports_ready_failed_cards_separately_from_due_cards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
                'due_at' => Carbon::parse('2026-06-04T11:50:00Z'),
                'failed_at' => Carbon::parse('2026-06-04T11:00:00Z'),
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);

            $this->getJson('/api/study/overview')
                ->assertOk()
                ->assertJsonPath('dueCount', 0)
                ->assertJsonPath('failedCount', 1)
                ->assertJsonPath('newCardsAvailableToday', 0)
                ->assertJsonMissingPath('failedDueCount');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_normalizes_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create();
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->getJson('/api/study/overview?deck_id=%20'.strtoupper($deck->id).'%20')
            ->assertOk()
            ->assertJsonPath('newCount', 1)
            ->assertJsonPath('totalCards', 1);
    }

    public function test_show_normalizes_scope_filter_aliases_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        StudySettings::factory()->for($user)->create();
        $targetCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->getJson('/api/study/overview?course_id=%20'.strtoupper($course->id).'%20&deckId=%20'.strtoupper($deck->id).'%20')
            ->assertOk()
            ->assertJsonPath('newCount', 1)
            ->assertJsonPath('totalCards', 1);

        $this->assertSame($deck->id, $targetCard->refresh()->deck_id);
    }

    public function test_show_returns_empty_overview_when_course_and_deck_filters_do_not_match(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create();
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $this->getJson("/api/study/overview?courseId={$course->id}&deckId={$deck->id}")
            ->assertOk()
            ->assertJsonPath('newCount', 0)
            ->assertJsonPath('totalCards', 0);
    }

    public function test_show_returns_empty_overview_for_another_users_deck_id(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
        ]);

        $this->getJson("/api/study/overview?deck_id={$otherDeck->id}")
            ->assertOk()
            ->assertJsonPath('dueCount', 0)
            ->assertJsonPath('newCount', 0)
            ->assertJsonPath('totalCards', 0);
    }

    public function test_show_validates_time_zone_without_coercing_malformed_values(): void
    {
        $this->signIn();

        $this->getJson('/api/study/overview?time_zone=Not%2FA_Zone')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);

        $this->getJson('/api/study/overview?time_zone[]=America%2FNew_York')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);
    }

    public function test_show_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $this->getJson('/api/study/overview?deck_id=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->getJson('/api/study/overview?deck_id[]=01J00000000000000000000000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->getJson('/api/study/overview?deckId=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);

        $this->getJson('/api/study/overview?courseId=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->getJson('/api/study/overview?course_id=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);

        $this->getJson('/api/study/overview?course_id[]=01J00000000000000000000000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_show_rejects_conflicting_camel_and_legacy_scope_filters(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $this->getJson("/api/study/overview?courseId={$course->id}&course_id={$otherCourse->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->getJson("/api/study/overview?deckId={$deck->id}&deck_id={$otherDeck->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);
    }

    public function test_show_rejects_blank_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->signIn();

        $this->getJson('/api/study/overview?deck_id=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->getJson('/api/study/overview?deckId=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);

        $this->getJson('/api/study/overview?courseId=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->getJson('/api/study/overview?course_id=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }
}
