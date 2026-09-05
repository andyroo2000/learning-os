<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Jobs\ProcessStudyCardDraft;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StoreStudyCardDraftCompatibilityApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'cloze',
            'cardType' => 'cloze',
            'prompt' => ['clozeText' => '試合に[勝ちました]。'],
            'answer' => [],
        ])->assertUnauthorized();
    }

    public function test_it_creates_a_manual_study_card_draft(): void
    {
        Queue::fake();
        $user = $this->signIn();

        $response = $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'cloze',
            'cardType' => 'cloze',
            'prompt' => ['clozeText' => '試合に[勝ちました]。'],
            'answer' => [],
            'imagePlacement' => 'both',
            'imagePrompt' => null,
            'status' => 'ready',
            'errorMessage' => 'client-owned',
        ])
            ->assertCreated()
            ->assertJsonPath('status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('creationKind', StudyCardCreationKind::Cloze->value)
            ->assertJsonPath('cardType', CardType::Cloze->value)
            ->assertJsonPath('prompt.clozeText', '試合に[勝ちました]。')
            ->assertJsonPath('answer', [])
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', null)
            ->assertJsonPath('previewAudio', null)
            ->assertJsonPath('previewAudioRole', null)
            ->assertJsonPath('previewImage', null)
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null)
            ->assertJsonPath('errorMessage', null)
            ->assertJsonPath('committedCardId', null);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertNull($draft->error_message);
        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draft->id,
        );
    }

    public function test_it_is_idempotent_for_a_client_generated_draft_id(): void
    {
        Queue::fake();
        $this->signIn();
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => '  '.strtoupper($id).'  ',
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
        ];

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', $payload)
            ->assertCreated()
            ->assertJsonPath('id', $id);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', $payload)
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        Queue::assertPushed(ProcessStudyCardDraft::class, 1);
    }

    public function test_it_rejects_conflicting_client_generated_draft_ids(): void
    {
        Queue::fake();
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $this->postJson('/api/study/card-drafts', [
            'id' => $id,
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
        ])->assertCreated();

        $this->postJson('/api/study/card-drafts', [
            'id' => $id,
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '猫'],
            'answer' => ['meaning' => 'cat'],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Draft ID already exists with different creation data.');

        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        Queue::assertPushed(ProcessStudyCardDraft::class, 1);
    }

    public function test_it_hides_client_generated_draft_id_collisions_owned_by_other_users(): void
    {
        Queue::fake();
        $id = strtolower((string) Str::ulid());
        StudyCardDraft::factory()->create(['id' => $id]);
        $this->signIn();

        $this->postJson('/api/study/card-drafts', [
            'id' => $id,
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');

        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_malformed_client_generated_draft_ids(): void
    {
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'id' => ' not-a-ulid ',
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id']);

        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
