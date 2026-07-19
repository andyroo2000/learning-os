<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class ResolveStudyCardPitchAccentApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'openai-test-key',
            'services.openai.base_url' => 'https://openai.test/v1',
            'services.openai.pitch_accent_model' => 'gpt-5.4-mini',
            'services.openai.pitch_accent_reasoning_effort' => 'low',
        ]);
    }

    public function test_it_resolves_from_a_local_reading_and_returns_the_compatibility_shape(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'prompt_json' => ['cueText' => 'company', 'cueReading' => 'かいしゃ'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
        ]);

        $response = $this->postJson("/api/study/cards/{$card->id}/pitch-accent");

        $response
            ->assertOk()
            ->assertJsonPath('answer.pitchAccent.status', 'resolved')
            ->assertJsonPath('answer.pitchAccent.expression', '会社')
            ->assertJsonPath('answer.pitchAccent.reading', 'かいしゃ')
            ->assertJsonPath('answer.pitchAccent.pitchNum', 0)
            ->assertJsonPath('answer.pitchAccent.morae', ['か', 'い', 'しゃ'])
            ->assertJsonPath('answer.pitchAccent.pattern', [0, 1, 1])
            ->assertJsonPath('answer.pitchAccent.patternName', '平板')
            ->assertJsonPath('answer.pitchAccent.source', 'kanjium')
            ->assertJsonPath('answer.pitchAccent.resolvedBy', 'local-reading');
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $this->assertSame(
            'resolved',
            $card->refresh()->answer_json['pitchAccent']['status'],
        );
        Http::assertNothingSent();
        $this->assertCardSyncEntry($user, $card);
    }

    public function test_it_preserves_alternative_pitch_numbers_for_the_same_reading(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '橋', 'expressionReading' => 'はし'],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")
            ->assertOk()
            ->assertJsonPath('answer.pitchAccent.pitchNum', 2)
            ->assertJsonPath('answer.pitchAccent.alternatives.0.reading', 'きょう');
    }

    public function test_it_uses_openai_only_to_disambiguate_multiple_readings(): void
    {
        Http::fake(function (Request $request) {
            $item = $request->data()['input'][1]['content'][0]['text'];
            $id = json_decode($item, true, flags: JSON_THROW_ON_ERROR)['items'][0]['id'];

            return Http::response([
                'output_text' => json_encode([
                    'choices' => [['id' => $id, 'reading' => 'にっぽん']],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        });
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => [
                'expression' => '日本',
                'sentenceJp' => '日本代表を応援します。',
            ],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")
            ->assertOk()
            ->assertJsonPath('answer.pitchAccent.reading', 'にっぽん')
            ->assertJsonPath('answer.pitchAccent.resolvedBy', 'llm');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://openai.test/v1/responses'
                && $data['model'] === 'gpt-5.4-mini'
                && $data['reasoning']['effort'] === 'low'
                && str_contains($data['input'][0]['content'][0]['text'], 'untrusted data');
        });
    }

    public function test_it_returns_unresolved_when_openai_fails_without_leaking_provider_details(): void
    {
        Http::fake([
            'openai.test/v1/responses' => Http::response([
                'error' => ['message' => 'secret quota detail'],
            ], 500),
        ]);
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '日本'],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")
            ->assertOk()
            ->assertJsonPath('answer.pitchAccent.status', 'unresolved')
            ->assertJsonPath('answer.pitchAccent.reason', 'ambiguous-reading')
            ->assertJsonPath('answer.pitchAccent.resolvedBy', 'llm');
    }

    public function test_it_returns_cached_resolved_data_without_a_write_or_provider_call(): void
    {
        Http::fake();
        $user = $this->signIn();
        $pitchAccent = [
            'status' => 'resolved',
            'expression' => '会社',
            'reading' => 'かいしゃ',
            'pitchNum' => 0,
            'morae' => ['か', 'い', 'しゃ'],
            'pattern' => [0, 1, 1],
            'patternName' => '平板',
            'source' => 'kanjium',
            'resolvedBy' => 'local-reading',
        ];
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '会社', 'pitchAccent' => $pitchAccent],
        ]);
        $updatedAt = $card->updated_at->toJSON();

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")
            ->assertOk()
            ->assertJsonPath('answer.pitchAccent', $pitchAccent);

        $this->assertSame($updatedAt, $card->refresh()->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Http::assertNothingSent();
    }

    public function test_it_uses_restored_cloze_text_for_resolution(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'card_type' => CardType::Cloze,
            'answer_json' => [
                'restoredText' => '会社[かいしゃ]',
                'restoredTextReading' => '会社[かいしゃ]',
            ],
        ]);

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")
            ->assertOk()
            ->assertJsonPath('answer.pitchAccent.expression', '会社')
            ->assertJsonPath('answer.pitchAccent.reading', 'かいしゃ');
    }

    public function test_it_hides_missing_cross_user_deleted_and_malformed_cards(): void
    {
        Http::fake();
        $user = $this->signIn();
        $otherCard = $this->studyCardFor(User::factory()->create());
        $deletedCard = $this->studyCardFor($user);
        $deletedCard->delete();
        $deletedDeckCard = $this->studyCardFor($user);
        $deletedDeckCard->deck()->delete();

        $this->postJson("/api/study/cards/{$otherCard->id}/pitch-accent")->assertNotFound();
        $this->postJson("/api/study/cards/{$deletedCard->id}/pitch-accent")->assertNotFound();
        $this->postJson("/api/study/cards/{$deletedDeckCard->id}/pitch-accent")->assertNotFound();
        $this->postJson('/api/study/cards/not-an-id/pitch-accent')->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_requires_authentication_and_rejects_all_body_fields(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $card = $this->studyCardFor($user);

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")->assertUnauthorized();

        $this->signIn($user);
        $this->postJson("/api/study/cards/{$card->id}/pitch-accent", ['unexpected' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unexpected']);
        $this->postJson("/api/study/cards/{$card->id}/pitch-accent", ['unexpected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['0']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_a_stale_resolution_when_the_card_changes_during_provider_work(): void
    {
        $user = $this->signIn();
        $card = $this->studyCardFor($user, [
            'answer_json' => ['expression' => '日本'],
        ]);
        Http::fake(function (Request $request) use ($card) {
            $card->forceFill(['back_text' => 'changed concurrently'])->save();
            $item = json_decode(
                $request->data()['input'][1]['content'][0]['text'],
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            return Http::response([
                'output_text' => json_encode([
                    'choices' => [[
                        'id' => $item['items'][0]['id'],
                        'reading' => 'にほん',
                    ]],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        });

        $this->postJson("/api/study/cards/{$card->id}/pitch-accent")
            ->assertConflict()
            ->assertExactJson([
                'message' => 'The study card changed while its pitch accent was being resolved. Please retry.',
            ]);

        $this->assertArrayNotHasKey('pitchAccent', $card->refresh()->answer_json);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_accepts_an_uppercase_copied_card_uuid(): void
    {
        Http::fake();
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'front_text' => 'company',
            'back_text' => '会社',
            'answer_json' => ['expression' => '会社'],
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->save();

        $this->postJson('/api/study/cards/C358732A-2CD0-4B18-9CCE-C474297863F9/pitch-accent')
            ->assertOk()
            ->assertJsonPath('id', 'c358732a-2cd0-4b18-9cce-c474297863f9');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function studyCardFor(User $user, array $attributes = []): Card
    {
        return Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'company',
            'back_text' => '会社',
            'prompt_json' => ['cueText' => 'company'],
            'answer_json' => ['expression' => '会社'],
            ...$attributes,
        ]);
    }

    private function assertCardSyncEntry(User $user, Card $card): void
    {
        $entry = SyncFeedEntry::query()
            ->where('user_id', $user->id)
            ->where('domain', CardSyncPayload::DOMAIN)
            ->where('resource_type', CardSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $card->id)
            ->where('operation', SyncFeedOperation::Update->value)
            ->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(CardSyncPayload::fromCard($card->refresh()), $entry->payload);
    }
}
