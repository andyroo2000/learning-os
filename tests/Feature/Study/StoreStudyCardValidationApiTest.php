<?php

namespace Tests\Feature\Study;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreStudyCardValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_manual_card_payloads_and_type_fields(): void
    {
        $this->signIn();

        $this->assertVariantMetadataValueAndTypeValidation();
        $this->assertVariantMetadataBoundaryValidation();
    }

    private function assertVariantMetadataValueAndTypeValidation(): void
    {
        $this->postJson('/api/study/cards', [
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardType'])
            ->assertJsonPath('errors.cardType.0', 'cardType must be recognition, production, or cloze.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'bad',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardType']);

        $this->postJson('/api/study/cards', [
            'creationKind' => 'bad',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['creationKind'])
            ->assertJsonPath('errors.creationKind.0', 'creationKind is not supported.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueAudio' => ['id' => 'media']],
            'answer' => ['answerAudio' => ['id' => 'media']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'answer'])
            ->assertJsonPath('errors.prompt.0', 'prompt must include a non-empty text field.')
            ->assertJsonPath('errors.answer.0', 'answer must include a non-empty text field.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads']);
    }

    public function test_it_validates_manual_card_variant_metadata(): void
    {
        $this->signIn();

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
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

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantUnlockedAt' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantUnlockedAt'])
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a string.');
    }

    private function assertVariantMetadataBoundaryValidation(): void
    {
        foreach ([
            '2026-02-31T14:15:30',
            '2026-06-04T14:15:30+15:00',
            '2026-06-04T14:15:30-13:00',
        ] as $variantUnlockedAt) {
            $this->postJson('/api/study/cards', [
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => $variantUnlockedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['variantUnlockedAt'])
                ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
        }

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantSentenceId' => str_repeat('b', 65),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantSentenceId'])
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be 64 characters or fewer.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
            'variantStage' => 65536,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantStage'])
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.');

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/cards', [
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantStage' => ' -1 ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantStage'])
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.');
    }
}
