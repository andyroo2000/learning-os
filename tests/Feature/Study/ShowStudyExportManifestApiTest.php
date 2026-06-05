<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShowStudyExportManifestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/study/export')->assertUnauthorized();
    }

    public function test_show_returns_the_authenticated_users_export_manifest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T12:34:56Z'));

        try {
            $user = $this->signIn();
            $otherUser = User::factory()->create();
            $course = Course::factory()->for($user)->create();
            $deck = $this->deckFor($user, ['course_id' => $course->id]);
            $card = Card::factory()->for($deck)->create();

            CardReviewEvent::factory()->for($card)->create();
            MediaAsset::factory()->for($user)->create();
            Course::factory()->for($otherUser)->create();
            Card::factory()->for($this->deckFor($otherUser))->create();
            MediaAsset::factory()->for($otherUser)->create();

            $this->getJson('/api/study/export')
                ->assertOk()
                ->assertJsonPath('data.exported_at', '2026-06-05T12:34:56.000000Z')
                ->assertJsonPath('data.sections.courses.total', 1)
                ->assertJsonPath('data.sections.decks.total', 1)
                ->assertJsonPath('data.sections.cards.total', 1)
                ->assertJsonPath('data.sections.review_events.total', 1)
                ->assertJsonPath('data.sections.media_assets.total', 1)
                ->assertJsonStructure([
                    'data' => [
                        'exported_at',
                        'sections' => [
                            'courses' => ['total'],
                            'decks' => ['total'],
                            'cards' => ['total'],
                            'review_events' => ['total'],
                            'media_assets' => ['total'],
                        ],
                    ],
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
