<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Http\Resources\Flashcards\CardResource;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Flashcards\Concerns\UsesStudyCardRateLimitOverrides;
use Tests\TestCase;

class UpdateCardApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyCardRateLimitOverrides;

    public function test_it_updates_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.deck_id', $card->deck_id)
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null)
            ->assertJsonPath('data.search_text', 'arrivederci goodbye')
            ->assertJsonMissingPath('data.media_assets')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'deck_id',
                    'course_id',
                    'front_text',
                    'back_text',
                    'card_type',
                    'prompt_json',
                    'answer_json',
                    'search_text',
                    'study_status',
                    'new_queue_position',
                    'scheduler_state',
                    'due_at',
                    'introduced_at',
                    'failed_at',
                    'last_reviewed_at',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
            ]);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'recognition',
            'prompt_json' => null,
            'answer_json' => null,
            'search_text' => 'arrivederci goodbye',
        ]);
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '  arrivederci  ',
            'back_text' => '  goodbye  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye');
    }

    public function test_update_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => 'original user front',
            'back_text' => 'original user back',
        ]);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser, [
            'front_text' => 'original other front',
            'back_text' => 'original other back',
        ]);

        $this->withStudyCardRateLimitOverride(
            StudyCardUpdateRateLimiter::NAME,
            [$user->id, $otherUser->id],
            function () use ($card, $otherCard, $otherUser, $user): void {
                foreach ([1, 2] as $attempt) {
                    $this
                        ->putJson("/api/cards/{$card->id}", $this->cardUpdatePayload("user front {$attempt}"))
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->putJson("/api/cards/{$otherCard->id}", $this->cardUpdatePayload('other front'))
                    ->assertOk();

                $this->signIn($user);

                $this
                    ->putJson("/api/cards/{$card->id}", $this->cardUpdatePayload('blocked front'))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/cards/{$card->id}")
                    ->assertOk()
                    ->assertJsonPath('data.front_text', 'user front 2');

                $this->assertSame('user front 2', $card->refresh()->front_text);
                $this->assertSame('other front', $otherCard->refresh()->front_text);
            },
        );
    }

    public function test_it_ignores_client_provided_study_state(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'study_status' => 'review',
            'new_queue_position' => 99,
            'scheduler_state' => ['state' => 2],
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.study_status', 'new')
            ->assertJsonPath('data.new_queue_position', $card->new_queue_position)
            ->assertJsonPath('data.scheduler_state', null)
            ->assertJsonPath('data.due_at', null)
            ->assertJsonPath('data.introduced_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.last_reviewed_at', null);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'new',
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    public function test_it_updates_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => 'arrivederci',
                'back_text' => 'goodbye',
                'card_type' => ' CLOZE ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.card_type', 'cloze');

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'cloze',
        ]);
    }

    public function test_it_updates_structured_content(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.prompt_json.type', 'text')
            ->assertJsonPath('data.prompt_json.text', 'What is ATP?')
            ->assertJsonPath('data.answer_json.type', 'text')
            ->assertJsonPath('data.answer_json.text', 'Cellular energy currency.');

        $card->refresh();

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $card->answer_json);
    }

    public function test_it_clears_structured_content_when_explicit_nulls_are_provided(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => null,
            'answer_json' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $card->refresh();

        $this->assertNull($card->prompt_json);
        $this->assertNull($card->answer_json);
    }

    public function test_it_updates_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => '  会社  ',
                'back_text' => '  company  ',
                'variant_group_id' => ' vocab-group-1 ',
                'variant_sentence_id' => ' sentence-1 ',
                'variant_kind' => ' SENTENCE_CLOZE ',
                'variant_stage' => ' 3 ',
                'variant_status' => ' AVAILABLE ',
                'variant_unlocked_at' => '2026-06-04T14:15:30+05:30',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.front_text', '会社')
            ->assertJsonPath('data.back_text', 'company')
            ->assertJsonPath('data.variant_group_id', 'vocab-group-1')
            ->assertJsonPath('data.variant_sentence_id', 'sentence-1')
            ->assertJsonPath('data.variant_kind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('data.variant_stage', 3)
            ->assertJsonPath('data.variant_status', VocabVariantStatus::Available->value)
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-04T08:45:30.000000Z');

        $card->refresh();
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertSame('sentence-1', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $card->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame('vocab-group-1', $entry->payload['variant_group_id']);
        $this->assertSame('sentence-1', $entry->payload['variant_sentence_id']);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $entry->payload['variant_kind']);
        $this->assertSame(3, $entry->payload['variant_stage']);
        $this->assertSame(VocabVariantStatus::Available->value, $entry->payload['variant_status']);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $entry->payload['variant_unlocked_at']);
    }

    public function test_it_clears_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => '会社',
                'back_text' => 'company',
                'variant_group_id' => '   ',
                'variant_sentence_id' => "\t",
                'variant_kind' => null,
                'variant_stage' => null,
                'variant_status' => null,
                'variant_unlocked_at' => null,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.variant_group_id', null)
            ->assertJsonPath('data.variant_sentence_id', null)
            ->assertJsonPath('data.variant_kind', null)
            ->assertJsonPath('data.variant_stage', null)
            ->assertJsonPath('data.variant_status', null)
            ->assertJsonPath('data.variant_unlocked_at', null);

        $card->refresh();
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertNull($entry->payload['variant_group_id']);
        $this->assertNull($entry->payload['variant_sentence_id']);
        $this->assertNull($entry->payload['variant_kind']);
        $this->assertNull($entry->payload['variant_stage']);
        $this->assertNull($entry->payload['variant_status']);
        $this->assertNull($entry->payload['variant_unlocked_at']);
    }

    public function test_it_preserves_structured_content_when_omitted(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.prompt_json.type', 'text')
            ->assertJsonPath('data.prompt_json.text', 'What is ATP?')
            ->assertJsonPath('data.answer_json.type', 'text')
            ->assertJsonPath('data.answer_json.text', 'Cellular energy currency.');

        $card->refresh();

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $card->answer_json);
    }

    public function test_it_is_idempotent_when_text_is_unchanged(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_is_idempotent_when_trimmed_text_matches_existing_values(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_is_idempotent_when_variant_metadata_matches_existing_values(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $card->refresh();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => '会社',
                'back_text' => 'company',
                'variant_group_id' => ' keep-group ',
                'variant_sentence_id' => ' keep-sentence ',
                'variant_kind' => ' SENTENCE_AUDIO_RECOGNITION ',
                'variant_stage' => ' 2 ',
                'variant_status' => ' LOCKED ',
                'variant_unlocked_at' => '2026-06-05T14:15:00.987654Z',
            ]);

        $card->refresh();

        // UpdateCardAction compares variant_unlocked_at at persisted second precision.
        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at'])
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-05T14:15:00.000000Z');

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'updated_at' => $timestamp,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_updates_timestamp_when_text_changes(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertTrue($card->updated_at->isAfter($timestamp));
        $this->assertNotSame($timestamp->toJSON(), $response->json('data.updated_at'));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);
    }

    public function test_it_rejects_blank_front_text(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '   ',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_text_fields_together(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_non_string_text_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => ['arrivederci'],
            'back_text' => ['goodbye'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_card_type_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => 'arrivederci',
                'back_text' => 'goodbye',
                'card_type' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_malformed_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'reverse',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_null_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => null,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_array_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => ['cloze'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_non_array_structured_content(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => 'What is ATP?',
            'answer_json' => 'Cellular energy currency.',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt_json', 'answer_json']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'prompt_json' => null,
            'answer_json' => null,
        ]);
    }

    public function test_it_rejects_invalid_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_group_id' => str_repeat('a', 65),
            'variant_sentence_id' => ['sentence-1'],
            'variant_kind' => 'sentence-audio-recognition',
            'variant_stage' => 0,
            'variant_status' => ['available'],
            'variant_unlocked_at' => 'yesterday',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);

        $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_unlocked_at' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variant_unlocked_at']);

        $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_unlocked_at' => '2026-06-04T14:15:30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variant_unlocked_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_missing_required_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_partial_updates(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $missingFrontText = $this->putJson("/api/cards/{$card->id}", [
            'back_text' => 'goodbye',
        ]);

        $missingFrontText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $missingBackText = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
        ]);

        $missingBackText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$otherCard->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $otherCard->id,
            'front_text' => $otherCard->front_text,
            'back_text' => $otherCard->back_text,
        ]);
    }

    public function test_it_authorizes_before_validating_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$otherCard->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['front_text', 'back_text']);
    }

    public function test_it_returns_not_found_for_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();

        $deck->delete();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();
        $missingCardId = (string) Str::ulid();

        $response = $this->putJson("/api/cards/{$missingCardId}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_card_id(): void
    {
        $this->signIn();

        $response = $this->putJson('/api/cards/not-a-ulid', [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_does_not_accept_patch_updates(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertStatus(405);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    /**
     * @return array{front_text: string, back_text: string}
     */
    private function cardUpdatePayload(string $frontText): array
    {
        return [
            'front_text' => $frontText,
            'back_text' => 'back '.$frontText,
        ];
    }
}
