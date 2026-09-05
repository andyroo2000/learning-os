<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardCompatibilityApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_updates_a_copied_card_by_its_convolab_identifier(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'front_text' => 'old copied prompt',
            'back_text' => 'old copied answer',
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->convolab_note_id = '9e33f12d-cf38-409b-bbf1-6fddd9977576';
        $card->save();

        $this->patchJson('/api/study/cards/C358732A-2CD0-4B18-9CCE-C474297863F9', [
            'prompt' => ['cueText' => 'updated copied prompt'],
            'answer' => ['expression' => 'updated copied answer'],
        ])
            ->assertOk()
            ->assertJsonPath('id', $card->convolab_id)
            ->assertJsonPath('noteId', $card->convolab_note_id)
            ->assertJsonPath('state.source.noteId', null)
            ->assertJsonPath('prompt.cueText', 'updated copied prompt')
            ->assertJsonPath('answer.expression', 'updated copied answer');

        $card->refresh();

        $this->assertSame(['cueText' => 'updated copied prompt'], $card->prompt_json);
        $this->assertSame(['expression' => 'updated copied answer'], $card->answer_json);
    }

    public function test_it_updates_a_study_card_from_prompt_and_answer_payloads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T14:15:00Z'));

        try {
            $user = $this->signIn();
            $card = Card::factory()->for($this->deckFor($user))->create([
                'front_text' => 'old front',
                'back_text' => 'old back',
                'study_status' => CardStudyStatus::Review,
                'source_note_id' => 701,
                'source_card_id' => 901,
                'source_deck_id' => 301,
                'source_notetype_name' => 'Japanese - Vocab',
                'source_template_ord' => 1,
                'scheduler_state' => ['state' => 2],
            ]);

            $this->assertNull($card->prompt_json);
            $this->assertNull($card->answer_json);

            $response = $this->patchJson("/api/study/cards/{$card->id}", [
                'prompt' => [
                    'cueText' => '会社',
                    'cueReading' => 'かいしゃ',
                ],
                'answer' => [
                    'expression' => '会社',
                    'meaning' => 'company',
                ],
                'study_status' => 'new',
                'new_queue_position' => 99,
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('id', $card->id)
                ->assertJsonPath('noteId', '701')
                ->assertJsonPath('cardType', 'recognition')
                ->assertJsonPath('prompt.cueText', '会社')
                ->assertJsonPath('prompt.cueReading', 'かいしゃ')
                ->assertJsonPath('answer.expression', '会社')
                ->assertJsonPath('answer.meaning', 'company')
                ->assertJsonPath('state.queueState', 'review')
                ->assertJsonPath('state.scheduler.state', 2)
                ->assertJsonPath('state.source.noteId', '701')
                ->assertJsonPath('state.source.cardId', '901')
                ->assertJsonPath('state.source.deckId', '301')
                ->assertJsonPath('answerAudioSource', 'missing');

            $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

            $card->refresh();

            $this->assertSame('会社', $card->front_text);
            $this->assertSame('会社', $card->back_text);
            $this->assertSame('2026-06-05T14:15:00.000000Z', $card->updated_at?->toJSON());
            $this->assertSame(['cueText' => '会社', 'cueReading' => 'かいしゃ'], $card->prompt_json);
            $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $card->answer_json);
            $this->assertSame('会社 会社 会社 かいしゃ 会社 company', $card->search_text);
            $this->assertSame(CardStudyStatus::Review, $card->study_status);

            $entry = SyncFeedEntry::query()->sole();
            $this->assertSame($user->id, $entry->user_id);
            $this->assertSame('flashcards', $entry->domain);
            $this->assertSame('card', $entry->resource_type);
            $this->assertSame($card->id, $entry->resource_id);
            $this->assertSame(SyncFeedOperation::Update, $entry->operation);
            $this->assertSame(CardSyncPayload::fromCard($card), $entry->payload);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_saves_a_regenerated_audio_recognition_card_without_prompt_text(): void
    {
        $user = $this->signIn();
        $mediaId = (string) Str::ulid();
        $prompt = [
            'cueAudio' => [
                'id' => $mediaId,
                'url' => "/api/study/media/{$mediaId}",
            ],
        ];
        $answer = [
            'expression' => '学校で偉人について勉強しました。',
            'meaning' => 'I studied great people at school.',
            'answerAudio' => $prompt['cueAudio'],
        ];
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => $answer['expression'],
            'back_text' => $answer['expression'],
            'card_type' => CardType::Recognition,
            'prompt_json' => $prompt,
            'answer_json' => $answer,
        ]);

        $response = $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => $prompt,
            'answer' => $answer,
        ])
            ->assertOk()
            ->assertJsonPath('prompt.cueAudio.id', $prompt['cueAudio']['id'])
            ->assertJsonPath('answer.answerAudio.id', $prompt['cueAudio']['id']);

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card->refresh();
        $this->assertSame($answer['expression'], $card->front_text);
        $this->assertSame($answer['expression'], $card->back_text);
        $this->assertSame($prompt, $card->prompt_json);
        $this->assertSame($answer, $card->answer_json);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_audio_recognition_save_still_requires_prompt_audio_and_answer_text(): void
    {
        $user = $this->signIn();
        $prompt = ['cueAudio' => ['id' => (string) Str::ulid()]];
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => '会社',
            'card_type' => CardType::Recognition,
            'prompt_json' => $prompt,
            'answer_json' => ['expression' => '会社'],
        ]);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueAudio' => []],
            'answer' => ['expression' => '会社'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.prompt.0', 'prompt must include a non-empty text field.');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => $prompt,
            'answer' => ['answerAudio' => $prompt['cueAudio']],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.answer.0', 'answer must include a non-empty text field.');
    }

    public function test_it_uses_fallback_payload_text_keys(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'old cloze',
            'back_text' => 'old answer',
        ]);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => [
                'clozeDisplayText' => '彼は{{c1::学生}}です',
            ],
            'answer' => [
                'restoredText' => '彼は学生です',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('prompt.clozeDisplayText', '彼は{{c1::学生}}です')
            ->assertJsonPath('answer.restoredText', '彼は学生です');

        $card->refresh();

        $this->assertSame('彼は{{c1::学生}}です', $card->front_text);
        $this->assertSame('彼は学生です', $card->back_text);
    }
}
