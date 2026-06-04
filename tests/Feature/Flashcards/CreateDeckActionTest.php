<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\CreateDeckAction;
use App\Domain\Flashcards\Data\CreateDeckData;
use App\Domain\Flashcards\Exceptions\DeckConflictException;
use App\Domain\Flashcards\Exceptions\DeckCourseNotFoundException;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CreateDeckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_deck_with_a_name(): void
    {
        $user = User::factory()->create();

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($result->wasCreated);
        $this->assertTrue(Str::isUlid($deck->id));

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $user->id,
            'course_id' => null,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('deck', $entry->resource_type);
        $this->assertSame($deck->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entry->operation);
        $this->assertSame([
            'id' => $deck->id,
            'course_id' => null,
            'name' => 'Italian Basics',
            'description' => null,
            'created_at' => $deck->created_at?->toJSON(),
            'updated_at' => $deck->updated_at?->toJSON(),
            'deleted_at' => null,
        ], $entry->payload);
    }

    public function test_it_creates_a_deck_for_an_owned_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                courseId: strtoupper($course->id),
                name: 'Italian Basics',
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($course->id, $deck->course_id);
        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'Italian Basics',
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($course->id, $entry->payload['course_id']);
    }

    public function test_it_rejects_decks_for_missing_or_cross_user_courses(): void
    {
        $user = User::factory()->create();
        $otherUserCourse = Course::factory()->create();

        $this->expectException(DeckCourseNotFoundException::class);
        $this->expectExceptionMessage('Course not found.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                courseId: $otherUserCourse->id,
                name: 'Italian Basics',
            ),
        );
    }

    public function test_it_creates_a_deck_with_a_description(): void
    {
        $user = User::factory()->create();

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: 'Foundational Italian review cards.',
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($result->wasCreated);
        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $user->id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                id: strtoupper($id),
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $deck->id);

        $this->assertDatabaseHas('decks', [
            'id' => strtolower($id),
            'user_id' => $user->id,
            'name' => 'Italian Basics',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $user = User::factory()->create();

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: '  Italian Basics  ',
                description: '  Foundational Italian review cards.  ',
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($result->wasCreated);
        $this->assertSame('Italian Basics', $deck->name);
        $this->assertSame('Foundational Italian review cards.', $deck->description);
    }

    public function test_it_stores_blank_description_as_null(): void
    {
        $user = User::factory()->create();

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: '   ',
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($result->wasCreated);
        $this->assertNull($deck->description);
    }

    public function test_it_rejects_blank_name(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck name is required.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(userId: $user->id, name: '   '),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck ID must be a valid ULID.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                id: 'not-a-ulid',
            ),
        );
    }

    public function test_it_returns_existing_deck_for_idempotent_retries(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingDeck = Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: '   ',
                id: strtoupper($id),
            ),
        );
        $deck = $result->deck;

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingDeck->is($deck));
        $this->assertDatabaseCount('decks', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_includes_course_scope_when_matching_idempotent_retries(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $id = strtolower((string) Str::ulid());

        $existingDeck = Deck::factory()->for($course)->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $result = app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                courseId: strtoupper($course->id),
                name: 'Italian Basics',
                description: '   ',
                id: strtoupper($id),
            ),
        );

        $this->assertFalse($result->wasCreated);
        $this->assertTrue($existingDeck->is($result->deck));
        $this->assertDatabaseCount('decks', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_idempotent_retries_with_a_different_course_scope(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $otherCourse = Course::factory()->create(['user_id' => $user->id]);
        $id = strtolower((string) Str::ulid());

        Deck::factory()->for($course)->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $this->expectException(DeckConflictException::class);
        $this->expectExceptionMessage('Deck ID already exists with different metadata.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                courseId: $otherCourse->id,
                name: 'Italian Basics',
                description: '   ',
                id: $id,
            ),
        );
    }

    public function test_it_returns_existing_deck_when_concurrent_create_wins_the_race(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $transactionLevelBeforeAction = DB::transactionLevel();
        $transactionLevelAfterRollback = null;

        DB::listen(function (QueryExecuted $query) use (&$inserted, $id, $user): void {
            if ($inserted || ! in_array($id, $query->bindings, true)) {
                return;
            }

            $inserted = true;

            DB::table('decks')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'name' => 'Italian Basics',
                'description' => 'Foundational Italian review cards.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $createDeck = new CreateDeckAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdUniqueConflict: function () use (&$transactionLevelAfterRollback): void {
                $transactionLevelAfterRollback = DB::transactionLevel();
            },
        );

        $result = $createDeck->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Italian Basics',
                description: 'Foundational Italian review cards.',
                id: $id,
            ),
        );
        $deck = $result->deck;

        $this->assertTrue($inserted);
        $this->assertFalse($result->wasCreated);
        $this->assertSame($transactionLevelBeforeAction, $transactionLevelAfterRollback);
        $this->assertSame($id, $deck->id);
        $this->assertDatabaseCount('decks', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rolls_back_the_deck_when_recording_sync_feed_fails(): void
    {
        $user = User::factory()->create();

        $createDeck = new CreateDeckAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sync feed failed.');

        try {
            $createDeck->handle(
                CreateDeckData::fromInput(
                    userId: $user->id,
                    name: 'Italian Basics',
                ),
            );
        } finally {
            $this->assertDatabaseCount('decks', 0);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $this->expectException(DeckConflictException::class);
        $this->expectExceptionMessage('Deck ID already exists with different metadata.');

        app(CreateDeckAction::class)->handle(
            CreateDeckData::fromInput(
                userId: $user->id,
                name: 'Spanish Basics',
                id: $id,
            ),
        );
    }
}
