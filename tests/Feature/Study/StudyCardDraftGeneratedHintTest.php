<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\ProcessStudyCardDraftAction;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use App\Domain\Study\Support\StudyCardPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class StudyCardDraftGeneratedHintTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_reading_hints_never_reach_the_ready_draft_or_saved_card(): void
    {
        $this->mockGeneratedPrompt(['cueMeaning' => 'It has been getting colder recently.']);
        $draft = $this->createDraft('text-recognition', [
            'cueText' => '最近、寒くなってきました。',
        ]);

        app(ProcessStudyCardDraftAction::class)->handle($draft->id);

        $ready = $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()->assertJsonPath('status', 'ready');
        $this->assertArrayNotHasKey('cueMeaning', $ready->json('prompt'));
        $this->assertArrayNotHasKey('cueMeaning', $draft->refresh()->prompt_json);
        $card = $this->commitDraft($draft);
        $this->assertArrayNotHasKey('cueMeaning', $card->prompt_json);
        $presentation = StudyCardPresentation::fromCard($card);
        $this->assertNull($presentation['front']['hint']);
        $this->assertSame('最近、寒くなってきました。', $presentation['front']['text']);
        $this->assertSame('It has been getting colder recently.', $presentation['answer']['meaning']);
    }

    public function test_generated_cloze_hints_remain_visible_after_draft_commit(): void
    {
        $this->mockGeneratedPrompt(['clozeHint' => 'has been getting colder; polite past']);
        $draft = $this->createDraft('cloze', [
            'clozeText' => '最近、{{c1::寒くなってきました}}。',
        ]);

        app(ProcessStudyCardDraftAction::class)->handle($draft->id);

        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonPath('prompt.clozeHint', 'has been getting colder; polite past');
        $card = $this->commitDraft($draft);
        $this->assertSame('has been getting colder; polite past', $card->prompt_json['clozeHint']);
        $presentation = StudyCardPresentation::fromCard($card);
        $this->assertSame('has been getting colder; polite past', $presentation['front']['hint']);
        $this->assertSame('最近、寒くなってきました。', $presentation['answer']['restored']);
    }

    private function createDraft(string $kind, array $prompt): StudyCardDraft
    {
        Queue::fake();
        $this->signIn();
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => $kind,
            'cardType' => $kind === 'cloze' ? 'cloze' : 'recognition',
            'imagePlacement' => 'none',
            'prompt' => $prompt,
            'answer' => [
                'expression' => '最近、寒くなってきました。',
                'restoredText' => '最近、寒くなってきました。',
                'meaning' => 'It has been getting colder recently.',
            ],
        ])->assertCreated();

        return StudyCardDraft::query()->sole();
    }

    private function commitDraft(StudyCardDraft $draft): Card
    {
        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", [
            'id' => strtolower((string) str()->ulid()),
        ])->assertCreated();

        return Card::query()->sole();
    }

    private function mockGeneratedPrompt(array $prompt): void
    {
        $this->mock(OpenAiStudyCardGenerator::class, function (MockInterface $mock) use ($prompt): void {
            $mock->shouldReceive('generateJson')->once()->andReturn(json_encode([
                'prompt' => $prompt,
                'answer' => [],
                'imagePrompt' => null,
            ], JSON_THROW_ON_ERROR));
        });
    }
}
