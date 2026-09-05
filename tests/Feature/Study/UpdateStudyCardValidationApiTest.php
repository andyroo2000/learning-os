<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardValidationApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_validates_payloads(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $this->assertRequiredPayloadValidation($card);
        $this->assertPayloadFieldValidation($card);
        $this->assertPayloadContentValidation($card);
    }

    private function assertRequiredPayloadValidation(Card $card): void
    {
        $this->patchJson("/api/study/cards/{$card->id}", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'prompt and answer payloads are required. (and 1 more error)');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueAudio' => ['id' => 'media']],
            'answer' => ['answerAudio' => ['id' => 'media']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'answer'])
            ->assertJsonPath('errors.prompt.0', 'prompt must include a non-empty text field.')
            ->assertJsonPath('errors.answer.0', 'answer must include a non-empty text field.');
    }

    private function assertPayloadFieldValidation(Card $card): void
    {
        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front', 'cueReading' => ['not text']],
            'answer' => [
                'meaning' => 'back',
                'notes' => false,
                'answerAudio' => ['filename' => 123],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'prompt.cueReading',
                'answer.notes',
                'answer.answerAudio.filename',
            ])
            ->assertJsonFragment([
                'prompt.cueReading' => ['prompt.cueReading must be a string or null.'],
                'answer.notes' => ['answer.notes must be a string or null.'],
                'answer.answerAudio.filename' => [
                    'answer.answerAudio.filename must be a string or null.',
                ],
            ]);
    }

    private function assertPayloadContentValidation(Card $card): void
    {
        $tooDeep = 'too deep';
        // Eight wraps below prompt/answer nested fields reaches depth 9 from the payload root.
        for ($depth = 0; $depth < 8; $depth++) {
            $tooDeep = ['nested' => $tooDeep];
        }

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front', 'nested' => $tooDeep],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt']);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back', 'nested' => $tooDeep],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer']);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['nested' => $tooDeep],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt'])
            ->assertJsonCount(1, 'errors.prompt')
            ->assertJsonPath('errors.prompt.0', 'prompt must be 8 levels deep or fewer.');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads']);

        $this
            ->withHeaders(['Accept' => 'application/json'])
            // Use form encoding so the invalid UTF-8 byte reaches request validation.
            ->patch("/api/study/cards/{$card->id}", [
                'prompt' => ['cueText' => "\xB1"],
                'answer' => ['meaning' => 'back'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads'])
            ->assertJsonPath('errors.payloads.0', 'study card payloads contain invalid content.');
    }

    public function test_it_validates_expected_revision(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();
        $payload = [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ];

        foreach ([-1, 'not-an-integer', ['0']] as $invalidRevision) {
            $this->patchJson("/api/study/cards/{$card->id}", [
                ...$payload,
                'expectedRevision' => $invalidRevision,
            ])->assertJsonValidationErrors(['expectedRevision']);
        }

        $this->patchJson("/api/study/cards/{$card->id}", [
            ...$payload,
            'expectedRevision' => '+0',
        ])->assertOk();
    }

    public function test_it_validates_variant_metadata(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantGroupId' => str_repeat('a', 65),
            'variantSentenceId' => ['sentence-1'],
            'variantKind' => 'sentence-audio-recognition',
            'variantStage' => 0,
            'variantStatus' => ['available'],
            'variantUnlockedAt' => 'yesterday',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variantGroupId',
                'variantSentenceId',
                'variantKind',
                'variantStage',
                'variantStatus',
                'variantUnlockedAt',
            ])
            ->assertJsonPath('errors.variantGroupId.0', 'variantGroupId must be 64 characters or fewer.')
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be a string.')
            ->assertJsonPath('errors.variantKind.0', 'variantKind is not supported.')
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.')
            ->assertJsonPath('errors.variantStatus.0', 'variantStatus must be a string.')
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantUnlockedAt' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantUnlockedAt'])
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a string.');

        foreach ([
            '2026-02-31T14:15:30',
            '2026-06-04T14:15:30+15:00',
            '2026-06-04T14:15:30-13:00',
        ] as $variantUnlockedAt) {
            $this->patchJson("/api/study/cards/{$card->id}", [
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => $variantUnlockedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['variantUnlockedAt'])
                ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
        }

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantSentenceId' => str_repeat('b', 65),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantSentenceId'])
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be 64 characters or fewer.');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantStage' => 65536,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantStage'])
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.');
    }

    public function test_it_accepts_payloads_at_the_maximum_depth_boundary(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $maxDepth = 'at boundary';
        // Seven wraps below prompt/answer nested fields reaches depth 8 from the payload root.
        for ($depth = 0; $depth < 7; $depth++) {
            $maxDepth = ['nested' => $maxDepth];
        }

        $response = $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front', 'nested' => $maxDepth],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', 'front')
            ->assertJsonPath('answer.meaning', 'back');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json());

        $card->refresh();

        $this->assertSame('front', $card->front_text);
        $this->assertSame('back', $card->back_text);
    }
}
