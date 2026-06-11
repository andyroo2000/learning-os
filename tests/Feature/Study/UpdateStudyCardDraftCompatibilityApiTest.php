<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateStudyCardDraftCompatibilityApiTest extends TestCase
{
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

            $this->patchJson("/api/study/card-drafts/{$draft->id}", [
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

    public function test_it_normalizes_optional_fields_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'image_prompt' => 'Old prompt',
            'preview_audio_json' => ['id' => 'old-audio', 'filename' => 'old.mp3', 'mediaKind' => 'audio', 'source' => 'generated'],
            'preview_audio_role' => StudyCardAudioRole::Answer,
            'preview_image_json' => ['id' => 'old-image', 'filename' => 'old.webp', 'mediaKind' => 'image', 'source' => 'generated'],
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceTextRecognition,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/study/card-drafts/{$draft->id}", [
                'prompt' => ['cueText' => '  company  '],
                'answer' => ['meaning' => '  会社  '],
                'imagePlacement' => ' BOTH ',
                'imagePrompt' => '   ',
                'previewAudio' => null,
                'previewAudioRole' => null,
                'previewImage' => null,
                'variantGroupId' => ' vocab-group-1 ',
                'variantSentenceId' => ' sentence-1 ',
                'variantKind' => ' SENTENCE_CLOZE ',
                'variantStage' => ' +3 ',
                'variantStatus' => ' AVAILABLE ',
                'variantUnlockedAt' => '2026-06-04T14:15:30+05:30',
            ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', '  company  ')
            ->assertJsonPath('answer.meaning', '  会社  ')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', null)
            ->assertJsonPath('previewAudio', null)
            ->assertJsonPath('previewAudioRole', null)
            ->assertJsonPath('previewImage', null)
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', 'sentence-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('variantStage', 3)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Available->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-04T08:45:30.000000Z');

        $draft->refresh();
        $this->assertNull($draft->image_prompt);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);
        $this->assertSame('vocab-group-1', $draft->variant_group_id);
        $this->assertSame('sentence-1', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $draft->variant_kind);
        $this->assertSame(3, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $draft->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $draft->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->latest('checkpoint')->firstOrFail();
        $this->assertSame(StudyCardDraftSyncPayload::fromDraft($draft), $entry->payload);

        $this
            ->patchJson("/api/study/card-drafts/{$draft->id}", [
                'imagePlacement' => null,
                'variantGroupId' => null,
                'variantSentenceId' => null,
                'variantKind' => null,
                'variantStage' => null,
                'variantStatus' => null,
                'variantUnlockedAt' => null,
            ])
            ->assertOk()
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $this->assertSame(StudyCardImagePlacement::None, $draft->refresh()->image_placement);
        $this->assertNull($draft->variant_group_id);
        $this->assertNull($draft->variant_sentence_id);
        $this->assertNull($draft->variant_kind);
        $this->assertNull($draft->variant_stage);
        $this->assertNull($draft->variant_status);
        $this->assertNull($draft->variant_unlocked_at);

        $entry = SyncFeedEntry::query()->latest('checkpoint')->firstOrFail();
        $this->assertSame(StudyCardDraftSyncPayload::fromDraft($draft), $entry->payload);
    }

    public function test_it_only_updates_present_fields(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['expression' => '会社', 'meaning' => 'business'],
        ])
            ->assertOk()
            ->assertJsonPath('answer.meaning', 'business')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', 'Keep this')
            ->assertJsonPath('variantGroupId', 'keep-group')
            ->assertJsonPath('variantSentenceId', 'keep-sentence')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Locked->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-05T14:15:00.000000Z');

        $draft->refresh();
        $this->assertSame(['expression' => '会社', 'meaning' => 'business'], $draft->answer_json);
        $this->assertSame('Keep this', $draft->image_prompt);
        $this->assertSame('keep-group', $draft->variant_group_id);
        $this->assertSame('keep-sentence', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $draft->variant_kind);
        $this->assertSame(2, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $draft->variant_status);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $draft->variant_unlocked_at?->toJSON());
    }

    public function test_empty_autosave_is_a_noop_and_returns_the_current_draft(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'image_prompt' => 'Keep this',
        ]);
        $originalUpdatedAt = $draft->updated_at?->toJSON();

        // Autosave clients can submit an empty body after debounced form churn; keep it a harmless readback.
        $this->patchJson("/api/study/card-drafts/{$draft->id}", [])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('imagePrompt', 'Keep this')
            ->assertJsonPath('updatedAt', $originalUpdatedAt);

        $this->assertSame($originalUpdatedAt, $draft->refresh()->updated_at?->toJSON());
    }

    public function test_it_rejects_generating_draft_edits(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->for($user)->create();

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Generating drafts cannot be edited yet.');
    }

    public function test_it_hides_missing_and_cross_user_drafts(): void
    {
        $this->signIn();
        $otherDraft = StudyCardDraft::factory()->ready()->for(User::factory()->create())->create();

        $this->patchJson("/api/study/card-drafts/{$otherDraft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertNotFound();

        $this->patchJson('/api/study/card-drafts/'.strtolower((string) str()->ulid()), [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertNotFound();
    }

    public function test_it_validates_autosave_payloads_and_preview_media(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create();

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'previewAudioRole' => 'front',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'answer', 'previewAudioRole'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.')
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole must be prompt or answer.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudioRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudioRole'])
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole requires previewAudio.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudio' => [
                'filename' => 'wrong.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'previewAudioRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudio.mediaKind', 'previewAudioRole'])
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole requires previewAudio.');

        $audioDraft = StudyCardDraft::factory()->ready()->for($user)->create([
            'preview_audio_json' => [
                'filename' => 'keep.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
        ]);

        $this->patchJson("/api/study/card-drafts/{$audioDraft->id}", [
            'previewAudio' => null,
            'previewAudioRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudioRole'])
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole requires previewAudio.');

        $audioDraft->refresh();
        $this->assertSame('keep.mp3', $audioDraft->preview_audio_json['filename']);
        $this->assertSame(StudyCardAudioRole::Answer, $audioDraft->preview_audio_role);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => [['cueText' => '会社']],
            'answer' => ['meaning' => 'company'],
            'imagePlacement' => 'sideways',
            'imagePrompt' => str_repeat('a', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'imagePlacement', 'imagePrompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.')
            ->assertJsonPath('errors.imagePlacement.0', 'imagePlacement must be none, prompt, answer, or both.')
            ->assertJsonPath('errors.imagePrompt.0', 'imagePrompt must be 1000 characters or fewer.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads'])
            ->assertJsonPath('errors.payloads.0', 'study card payloads must be 24 KB or smaller.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudio' => [
                'filename' => 'image.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'previewImage' => [
                'filename' => 'audio.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudio.mediaKind', 'previewImage.mediaKind'])
            ->assertJsonFragment([
                'previewAudio.mediaKind' => ['draft.previewAudio.mediaKind must be audio.'],
                'previewImage.mediaKind' => ['draft.previewImage.mediaKind must be image.'],
            ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudio' => [
                'mediaKind' => 'audio',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudio.filename', 'previewAudio.source'])
            ->assertJsonFragment([
                'previewAudio.filename' => ['draft.previewAudio.filename is required.'],
                'previewAudio.source' => ['draft media source must be imported, generated, missing, imported_image, or imported_other.'],
            ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewImage' => [
                'filename' => 'image.webp',
                'source' => 'external',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewImage.mediaKind', 'previewImage.source'])
            ->assertJsonFragment([
                'previewImage.mediaKind' => ['draft.previewImage.mediaKind must be image.'],
                'previewImage.source' => ['draft media source must be imported, generated, missing, imported_image, or imported_other.'],
            ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
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

        foreach ([
            '2026-02-31T14:15:30',
            '2026-06-04T14:15:30+15:00',
            '2026-06-04T14:15:30-13:00',
        ] as $variantUnlockedAt) {
            $this->patchJson("/api/study/card-drafts/{$draft->id}", [
                'variantUnlockedAt' => $variantUnlockedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['variantUnlockedAt'])
                ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
        }
    }

    public function test_it_rate_limits_manual_card_draft_autosaves_by_user(): void
    {
        $limiter = new StudyCardDraftAutosaveRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create();
        $otherUser = User::factory()->create();
        $previousServerVariables = $this->serverVariables;

        $restoreStudyCardDraftAutosaveLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this
                    ->patchJson("/api/study/card-drafts/{$draft->id}", [
                        'imagePrompt' => 'Autosave '.$attempt,
                    ])
                    ->assertOk();
            }

            $this
                ->patchJson("/api/study/card-drafts/{$draft->id}", [
                    'imagePrompt' => 'Too fast',
                ])
                ->assertTooManyRequests();

            $this->signIn($otherUser);
            $otherDraft = StudyCardDraft::factory()->ready()->for($otherUser)->create();

            $this
                ->patchJson("/api/study/card-drafts/{$otherDraft->id}", [
                    'imagePrompt' => 'Other user bucket',
                ])
                ->assertOk();
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardDraftAutosaveLimiter();
            $this->withServerVariables($previousServerVariables);
        }
    }
}
