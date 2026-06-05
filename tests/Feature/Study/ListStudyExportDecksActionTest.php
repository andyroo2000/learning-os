<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\ListStudyExportDecksAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportDecksActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_current_decks_for_the_user_in_stable_order(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($user)->create();

        $firstExportedDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'Second Deck',
            'created_at' => now(),
        ]);
        $secondExportedDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'First Deck',
            'created_at' => now()->subDay(),
        ]);
        $deletedDeck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'Deleted Deck',
        ]);

        Deck::factory()->create([
            'user_id' => $otherUser->id,
            'course_id' => Course::factory()->for($otherUser)->create()->id,
            'name' => 'Hidden Deck',
        ]);
        $deletedDeck->delete();

        $decks = app(ListStudyExportDecksAction::class)->handle($user->id);

        $this->assertSame(
            [$firstExportedDeck->id, $secondExportedDeck->id],
            $decks->pluck('id')->all(),
        );
        $this->assertSame(
            [$course->id, $course->id],
            $decks->pluck('course_id')->all(),
        );
    }
}
