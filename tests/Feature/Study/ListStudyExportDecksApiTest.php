<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportDecksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/decks')->assertUnauthorized();
    }

    public function test_index_returns_current_decks_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($otherUser)->create();

        $firstDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'Travel Basics',
            'description' => 'High-frequency starter phrases.',
        ]);
        $secondDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => null,
            'name' => 'Standalone Practice',
            'description' => null,
        ]);
        $deletedDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'Deleted Deck',
        ]);
        $otherDeck = Deck::factory()->create([
            'user_id' => $otherUser->id,
            'course_id' => $otherCourse->id,
            'name' => 'Hidden Deck',
        ]);

        $deletedDeck->delete();

        $this->getJson('/api/study/export/decks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstDeck->id)
            ->assertJsonPath('data.0.course_id', $course->id)
            ->assertJsonPath('data.0.name', 'Travel Basics')
            ->assertJsonPath('data.0.description', 'High-frequency starter phrases.')
            ->assertJsonPath('data.0.deleted_at', null)
            ->assertJsonPath('data.1.id', $secondDeck->id)
            ->assertJsonPath('data.1.course_id', null)
            ->assertJsonPath('data.1.name', 'Standalone Practice')
            ->assertJsonPath('data.1.description', null)
            ->assertJsonMissing([
                'id' => $deletedDeck->id,
            ])
            ->assertJsonMissing([
                'id' => $otherDeck->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'course_id',
                        'name',
                        'description',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
            ]);
    }
}
