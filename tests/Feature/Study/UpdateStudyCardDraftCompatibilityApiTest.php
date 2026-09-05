<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardDraftCompatibilityApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_update_requires_authentication(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertUnauthorized();
    }

    public function test_it_autosaves_a_manual_study_card_draft(): void
    {
        $this->travelTo(Carbon::parse('2026-06-05T14:15:00Z'), function (): void {
            $user = $this->signIn();
            $draft = StudyCardDraft::factory()->ready()->for($user)->create([
                'prompt_json' => ['cueText' => '会社'],
                'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
                'image_prompt' => 'Old prompt',
                'error_message' => null,
            ]);

            $this->travelTo(Carbon::parse('2026-06-05T14:16:00Z'));

            $response = $this->patchJson("/api/study/card-drafts/{$draft->id}", [
                'prompt' => ['cueText' => '会議'],
                'answer' => ['expression' => '会議', 'meaning' => 'meeting'],
                'imagePlacement' => 'answer',
                'imagePrompt' => 'A meeting room',
                'previewAudio' => [
                    'id' => 'audio-1',
                    'filename' => 'kaigi.mp3',
                    'url' => '/api/study/media/audio-1',
                    'mediaKind' => 'audio',
                    'source' => 'generated',
                ],
                'previewAudioRole' => 'prompt',
                'previewImage' => [
                    'id' => 'image-1',
                    'filename' => 'kaigi.webp',
                    'url' => '/api/study/media/image-1',
                    'mediaKind' => 'image',
                    'source' => 'generated',
                ],
                'status' => 'generating',
                'errorMessage' => 'client-owned',
            ])
                ->assertOk()
                ->assertJsonPath('id', $draft->id)
                ->assertJsonPath('status', StudyManualCardDraftStatus::Ready->value)
                ->assertJsonPath('prompt.cueText', '会議')
                ->assertJsonPath('answer.meaning', 'meeting')
                ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Answer->value)
                ->assertJsonPath('imagePrompt', 'A meeting room')
                ->assertJsonPath('previewAudio.id', 'audio-1')
                ->assertJsonPath('previewAudioRole', StudyCardAudioRole::Prompt->value)
                ->assertJsonPath('previewImage.id', 'image-1')
                ->assertJsonPath('errorMessage', null)
                ->assertJsonPath('updatedAt', '2026-06-05T14:16:00.000000Z');

            $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

            $draft->refresh();
            $this->assertSame(['cueText' => '会議'], $draft->prompt_json);
            $this->assertSame(['expression' => '会議', 'meaning' => 'meeting'], $draft->answer_json);
            $this->assertSame(StudyCardImagePlacement::Answer, $draft->image_placement);
            $this->assertSame('A meeting room', $draft->image_prompt);
            $this->assertSame('audio-1', $draft->preview_audio_json['id']);
            $this->assertSame(StudyCardAudioRole::Prompt, $draft->preview_audio_role);
            $this->assertSame('image-1', $draft->preview_image_json['id']);
            $this->assertSame(StudyManualCardDraftStatus::Ready, $draft->status);
            $this->assertNull($draft->error_message);
            $this->assertSame('2026-06-05T14:16:00.000000Z', $draft->updated_at?->toJSON());
        });
    }

    public function test_it_normalizes_uppercase_route_draft_ids(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $this->patchJson('/api/study/card-drafts/'.strtoupper($draft->id), [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'business'],
        ])
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('answer.meaning', 'business');
    }
}
