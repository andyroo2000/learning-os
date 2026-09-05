<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\StartStudyLessonAction;
use App\Domain\Study\Actions\StartStudySessionAction;
use App\Domain\Study\Models\StudySettings;
use App\Http\Resources\Study\StudySessionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionContractActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_new_cards_are_capped_by_the_lesson_batch_size(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 1000,
            'lesson_batch_size' => 5,
        ]);

        for ($position = 1; $position <= 7; $position++) {
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => $position,
            ]);
        }

        $result = app(StartStudyLessonAction::class)->handle($user->id);

        $this->assertCount(5, $result->cards);
        $this->assertSame(7, $result->overview['new_count']);
        $this->assertSame(7, $result->overview['new_cards_available_today']);
    }

    public function test_it_serializes_new_session_cards_without_a_separate_deck_lookup_query(): void
    {
        $this->assertSessionCardSerializationAvoidsDeckLookup(CardStudyStatus::New, [
            'new_queue_position' => 1,
        ], [
            'new_queue_position' => 2,
        ]);
    }

    public function test_it_serializes_due_session_cards_without_a_separate_deck_lookup_query(): void
    {
        $this->assertSessionCardSerializationAvoidsDeckLookup(CardStudyStatus::Review, [
            'due_at' => Carbon::parse('2026-06-04T11:30:00Z'),
        ], [
            'due_at' => Carbon::parse('2026-06-04T11:45:00Z'),
        ]);
    }

    public function test_it_rejects_invalid_time_zones_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study time_zone must be a valid IANA timezone.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            timeZone: 'Not/A_Zone',
        );
    }

    public function test_it_rejects_blank_deck_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study session deckId filter must not be blank when provided.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: '   ',
        );
    }

    public function test_it_rejects_invalid_deck_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study session deckId filter must be a valid ULID.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            deckId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_blank_course_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study session courseId filter must not be blank when provided.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: '   ',
        );
    }

    public function test_it_rejects_invalid_course_id_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study session courseId filter must be a valid ULID.');

        app(StartStudySessionAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: 'not-a-ulid',
        );
    }

    /**
     * @param  array<string, mixed>  $cardAttributes
     * @param  array<string, mixed>  $secondCardAttributes
     */
    private function assertSessionCardSerializationAvoidsDeckLookup(
        CardStudyStatus $status,
        array $cardAttributes,
        array $secondCardAttributes,
    ): void {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, [
            'course_id' => $course->id,
        ]);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $card = $this->cardWithStudyStatus($deck, $status, $cardAttributes);
        $secondCard = $this->cardWithStudyStatus($deck, $status, $secondCardAttributes);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $action = $status === CardStudyStatus::New
                ? app(StartStudyLessonAction::class)
                : app(StartStudySessionAction::class);
            $result = $action->handle(
                userId: $user->id,
                now: $now,
            );
            $payload = StudySessionResource::make($result)->response()->getData(true);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame($card->id, $payload['cards'][0]['id']);
        $this->assertSame($secondCard->id, $payload['cards'][1]['id']);
        $this->assertArrayNotHasKey('course_id', $payload['cards'][0]);
        $this->assertArrayNotHasKey('course_id', $payload['cards'][1]);

        $sessionCardSelects = $queries->filter(fn (array $query): bool => $this->isSelectFromTable($query['query'], 'cards')
            && preg_match('/^select\s+["`]?cards["`]?\.\*/i', $query['query']) === 1);

        $this->assertCount(
            $status === CardStudyStatus::New ? 3 : 1,
            $sessionCardSelects,
            "Expected the bounded lane queries for the selected study flow.\n"
                .$queries->pluck('query')->implode("\n"),
        );

        $standaloneDeckSelects = $queries->filter(fn (array $query): bool => $this->isSelectFromTable($query['query'], 'decks'));

        $this->assertCount(0, $standaloneDeckSelects, $queries->pluck('query')->implode("\n"));
    }

    private function isSelectFromTable(string $sql, string $table): bool
    {
        $normalizedSql = strtolower($sql);

        return str_starts_with($normalizedSql, 'select')
            && preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?/', $normalizedSql) === 1;
    }
}
