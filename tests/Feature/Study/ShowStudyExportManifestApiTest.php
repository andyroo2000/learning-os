<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Models\SyncFeedEntry;
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
            StudyCardDraft::factory()->for($user)->create();
            StudyImportJob::factory()->for($user)->create();
            $mediaAsset = MediaAsset::factory()->for($user)->create();
            $card->mediaAssets()->attach($mediaAsset->id);
            $currentCheckpoint = SyncFeedEntry::factory()->for($user)->create();
            Course::factory()->for($otherUser)->create();
            Card::factory()->for($this->deckFor($otherUser))->create();
            StudyCardDraft::factory()->for($otherUser)->create();
            StudyImportJob::factory()->for($otherUser)->create();
            MediaAsset::factory()->for($otherUser)->create();
            SyncFeedEntry::factory()->for($otherUser)->create();

            $this->getJson('/api/study/export')
                ->assertOk()
                ->assertJsonPath('data.exported_at', '2026-06-05T12:34:56.000000Z')
                ->assertJsonPath('data.current_checkpoint', $currentCheckpoint->checkpoint)
                ->assertJsonPath('data.sections.settings.total', 1)
                ->assertJsonPath('data.sections.courses.total', 1)
                ->assertJsonPath('data.sections.decks.total', 1)
                ->assertJsonPath('data.sections.cards.total', 1)
                ->assertJsonPath('data.sections.card_drafts.total', 1)
                ->assertJsonPath('data.sections.card_media.total', 1)
                ->assertJsonPath('data.sections.review_events.total', 1)
                ->assertJsonPath('data.sections.imports.total', 1)
                ->assertJsonPath('data.sections.media_assets.total', 1)
                ->assertJsonPath('data.sections.settings.path', '/api/study/export/settings')
                ->assertJsonPath('data.sections.courses.path', '/api/study/export/courses')
                ->assertJsonPath('data.sections.decks.path', '/api/study/export/decks')
                ->assertJsonPath('data.sections.cards.path', '/api/study/export/cards')
                ->assertJsonPath('data.sections.card_drafts.path', '/api/study/export/card-drafts')
                ->assertJsonPath('data.sections.card_media.path', '/api/study/export/card-media')
                ->assertJsonPath('data.sections.review_events.path', '/api/study/export/review-events')
                ->assertJsonPath('data.sections.imports.path', '/api/study/export/imports')
                ->assertJsonPath('data.sections.media_assets.path', '/api/study/export/media-assets')
                ->assertJsonStructure([
                    'data' => [
                        'exported_at',
                        'current_checkpoint',
                        'sections' => [
                            'settings' => ['total', 'path'],
                            'courses' => ['total', 'path'],
                            'decks' => ['total', 'path'],
                            'cards' => ['total', 'path'],
                            'card_drafts' => ['total', 'path'],
                            'card_media' => ['total', 'path'],
                            'review_events' => ['total', 'path'],
                            'imports' => ['total', 'path'],
                            'media_assets' => ['total', 'path'],
                        ],
                    ],
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
