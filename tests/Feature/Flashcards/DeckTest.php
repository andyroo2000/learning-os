<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class DeckTest extends TestCase
{
    use RefreshDatabase;

    public function test_decks_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('decks', [
            'id',
            'user_id',
            'course_id',
            'name',
            'description',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_deck_can_be_created_with_a_factory(): void
    {
        $deck = Deck::factory()->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $this->assertIsString($deck->id);
        $this->assertTrue(Str::isUlid($deck->id));

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $deck->user_id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_deck_belongs_to_a_user(): void
    {
        $deck = Deck::factory()->create();

        $this->assertIsInt($deck->user_id);
        $this->assertSame($deck->user_id, $deck->user->id);
    }

    public function test_deck_can_belong_to_a_course(): void
    {
        $course = Course::factory()->create();
        $deck = Deck::factory()->for($course)->create([
            'user_id' => $course->user_id,
        ]);

        $this->assertSame($course->id, $deck->course_id);
        $this->assertTrue($course->is($deck->course));
    }

    public function test_deck_owner_cannot_be_changed_after_creation(): void
    {
        $deck = Deck::factory()->create();
        $originalUserId = $deck->user_id;
        $deck->user_id = User::factory()->create()->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Deck owner cannot be changed.');

        try {
            $deck->save();
        } finally {
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'user_id' => $originalUserId,
            ]);
        }
    }

    public function test_deck_course_cannot_be_changed_after_creation(): void
    {
        $course = Course::factory()->create();
        $deck = Deck::factory()->for($course)->create([
            'user_id' => $course->user_id,
        ]);
        $originalCourseId = $deck->course_id;
        $deck->course_id = Course::factory()->create(['user_id' => $deck->user_id])->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Deck course cannot be changed.');

        try {
            $deck->save();
        } finally {
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'course_id' => $originalCourseId,
            ]);
        }
    }

    public function test_description_is_optional(): void
    {
        $deck = Deck::factory()->create([
            'description' => null,
        ]);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'description' => null,
        ]);
    }

    public function test_deck_can_be_soft_deleted(): void
    {
        $deck = Deck::factory()->create();

        $deck->delete();

        $this->assertSoftDeleted('decks', [
            'id' => $deck->id,
        ]);
    }
}
