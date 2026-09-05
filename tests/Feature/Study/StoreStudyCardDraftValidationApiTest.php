<?php

namespace Tests\Feature\Study;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreStudyCardDraftValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_card_type_payload_and_image_fields(): void
    {
        $this->signIn();

        $this->assertCardTypeValidation();
        $this->assertPayloadAndImageValidation();
        $this->assertVariantMetadataValidation();
    }

    private function assertCardTypeValidation(): void
    {
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'cloze',
            'cardType' => 'recognition',
            'prompt' => ['clozeText' => '試合に[勝ちました]。'],
            'answer' => ['meaning' => 'won'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardType'])
            ->assertJsonPath('errors.cardType.0', 'cardType must match creationKind.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'bad',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['creationKind'])
            ->assertJsonPath('errors.creationKind.0', 'creationKind is not supported.');
    }

    private function assertPayloadAndImageValidation(): void
    {
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => 'front',
            'answer' => ['meaning' => 'back'],
            'imagePlacement' => 'sideways',
            'imagePrompt' => str_repeat('a', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'imagePlacement', 'imagePrompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.')
            ->assertJsonPath('errors.imagePlacement.0', 'imagePlacement must be none, prompt, answer, or both.')
            ->assertJsonPath('errors.imagePrompt.0', 'imagePrompt must be 1000 characters or fewer.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => null,
            'answer' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads'])
            ->assertJsonPath('errors.payloads.0', 'study card payloads must be 24 KB or smaller.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'imagePrompt' => ['not' => 'a string'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imagePrompt']);

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => [['cueText' => 'front']],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt must be an object.')
            ->assertJsonCount(1, 'errors.prompt');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => [['meaning' => 'back']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer'])
            ->assertJsonPath('errors.answer.0', 'answer must be an object.')
            ->assertJsonCount(1, 'errors.answer');
    }

    private function assertVariantMetadataValidation(): void
    {
        $this->assertVariantMetadataValueValidation();
        $this->assertVariantMetadataTypeAndTimestampValidation();
    }

    private function assertVariantMetadataValueValidation(): void
    {
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantGroupId' => str_repeat('a', 65),
            'variantSentenceId' => str_repeat('b', 65),
            'variantKind' => 'sentence-audio-recognition',
            'variantStage' => 0,
            'variantStatus' => 'unknown',
            'variantUnlockedAt' => 'not-a-date',
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
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be 64 characters or fewer.')
            ->assertJsonPath('errors.variantKind.0', 'variantKind is not supported.')
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.')
            ->assertJsonPath('errors.variantStatus.0', 'variantStatus is not supported.')
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
    }

    private function assertVariantMetadataTypeAndTimestampValidation(): void
    {
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantGroupId' => ['vocab-group-1'],
            'variantSentenceId' => ['sentence-1'],
            'variantKind' => ['sentence_cloze'],
            'variantStage' => ['2'],
            'variantStatus' => ['available'],
            'variantUnlockedAt' => ['2026-06-04T14:15:30Z'],
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
            ->assertJsonPath('errors.variantGroupId.0', 'variantGroupId must be a string.')
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be a string.')
            ->assertJsonPath('errors.variantKind.0', 'variantKind must be a string.')
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be an integer.')
            ->assertJsonPath('errors.variantStatus.0', 'variantStatus must be a string.')
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a string.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantUnlockedAt' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantUnlockedAt'])
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a string.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantUnlockedAt' => 'yesterday',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantUnlockedAt'])
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');

        foreach ([
            '2026-02-31T14:15:30',
            '2026-06-04T14:15:30+15:00',
            '2026-06-04T14:15:30-13:00',
        ] as $variantUnlockedAt) {
            $this->postJson('/api/study/card-drafts', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => 'front'],
                'answer' => ['meaning' => 'back'],
                'variantUnlockedAt' => $variantUnlockedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['variantUnlockedAt'])
                ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
        }
    }
}
