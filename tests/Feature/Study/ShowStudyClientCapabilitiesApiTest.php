<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Support\StudyCardPayloadSchema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShowStudyClientCapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_server_owned_study_client_defaults_and_limits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/study/capabilities')
            ->assertOk()
            ->assertExactJson([
                'version' => 1,
                'settings' => [
                    'newCardsPerDay' => ['default' => 20, 'min' => 0, 'max' => 1000],
                    'lessonBatchSize' => ['default' => 5, 'min' => 3, 'max' => 10],
                    'reviewTimeBudgetMinutes' => ['default' => 90, 'min' => 15, 'max' => 240],
                    'newCardLaneWeights' => [
                        'standard' => ['default' => 3, 'min' => 1, 'max' => 20],
                        'lessonFollowup' => ['default' => 1, 'min' => 0, 'max' => 20],
                        'wanikani' => ['default' => 1, 'min' => 0, 'max' => 20],
                    ],
                ],
                'cardAuthoring' => [
                    'creationKinds' => [
                        'text-recognition',
                        'audio-recognition',
                        'production-text',
                        'production-image',
                        'cloze',
                    ],
                    'imagePlacements' => ['none', 'prompt', 'answer', 'both'],
                    'previewAudioRoles' => ['prompt', 'answer'],
                    'defaultAnswerAudioVoiceId' => 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f',
                    'defaultFemaleAnswerAudioVoiceId' => 'fishaudio:9639f090aa6346329d7d3aca7e6b7226',
                    'payloadSchema' => StudyCardPayloadSchema::jsonSchema(),
                    'limits' => [
                        'combinedPayloadBytes' => 24 * 1024,
                        'payloadDepth' => 8,
                        'imagePromptCharacters' => 1000,
                        'imageUploadBytes' => 10 * 1024 * 1024,
                    ],
                ],
                'dailyAudio' => [
                    'targetDurationMinutes' => ['default' => 30, 'min' => 5, 'max' => 60],
                ],
                'offlineReserve' => [
                    'days' => 5,
                    'maxScheduledCards' => 1000,
                ],
                'imports' => [
                    'maxArchiveBytes' => 2_147_483_648,
                ],
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/capabilities')->assertUnauthorized();
    }
}
