<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Http\Requests\Study\StoreStudyCardDraftRequest;
use App\Jobs\ProcessStudyCardDraft;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StoreStudyCardDraftVariantMetadataApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_creates_a_manual_study_card_draft_with_variant_metadata(): void
    {
        Queue::fake();
        $user = $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'creationKind' => ' text-recognition ',
                'cardType' => ' recognition ',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantGroupId' => ' vocab-group-1 ',
                'variantSentenceId' => ' sentence-1 ',
                'variantKind' => ' SENTENCE_AUDIO_RECOGNITION ',
                'variantStage' => ' +2 ',
                'variantStatus' => ' AVAILABLE ',
                'variantUnlockedAt' => '2026-06-04T14:15:30.987654Z',
            ])
            ->assertCreated()
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', 'sentence-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Available->value)
            // The storage column is second-precision, so fractional input is normalized away.
            ->assertJsonPath('variantUnlockedAt', '2026-06-04T14:15:30.000000Z');

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame('vocab-group-1', $draft->variant_group_id);
        $this->assertSame('sentence-1', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $draft->variant_kind);
        $this->assertSame(2, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $draft->variant_status);
        $this->assertSame('2026-06-04T14:15:30.000000Z', $draft->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame(StudyCardDraftSyncPayload::fromDraft($draft), $entry->payload);

        Queue::assertPushed(ProcessStudyCardDraft::class);
    }

    public function test_it_accepts_unsigned_string_variant_stage_without_trim_strings_middleware(): void
    {
        Queue::fake();
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantStage' => ' 2 ',
            ])
            ->assertCreated()
            ->assertJsonPath('variantStage', 2);

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame(2, $draft->variant_stage);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame(StudyCardDraftSyncPayload::fromDraft($draft), $entry->payload);

        Queue::assertPushed(ProcessStudyCardDraft::class);
    }

    public function test_request_treats_timezone_naive_variant_unlock_timestamps_as_utc(): void
    {
        $previousTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');

            $request = StoreStudyCardDraftRequest::create('/api/study/card-drafts', 'POST', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => '2026-06-04T14:15:30',
            ]);
            $request->setContainer($this->app)->setRedirector($this->app['redirect']);
            $request->validateResolved();

            $this->assertSame('2026-06-04T14:15:30.000000Z', $request->variantUnlockedAt()?->toJSON());

            $offsetRequest = StoreStudyCardDraftRequest::create('/api/study/card-drafts', 'POST', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => '2026-06-04T14:15:30+05:30',
            ]);
            $offsetRequest->setContainer($this->app)->setRedirector($this->app['redirect']);
            $offsetRequest->validateResolved();

            $this->assertSame('2026-06-04T08:45:30.000000Z', $offsetRequest->variantUnlockedAt()?->toJSON());

            $fractionalNaiveRequest = StoreStudyCardDraftRequest::create('/api/study/card-drafts', 'POST', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => '2026-06-04T14:15:30.987654',
            ]);
            $fractionalNaiveRequest->setContainer($this->app)->setRedirector($this->app['redirect']);
            $fractionalNaiveRequest->validateResolved();

            $this->assertSame('2026-06-04T14:15:30.987654Z', $fractionalNaiveRequest->variantUnlockedAt()?->toJSON());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_it_defaults_and_normalizes_optional_fields_without_trim_strings_middleware(): void
    {
        Queue::fake();
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'creationKind' => ' PRODUCTION-IMAGE ',
                'cardType' => ' PRODUCTION ',
                'prompt' => ['cueText' => '  company  '],
                'answer' => ['meaning' => '  会社  '],
                'imagePlacement' => null,
                'imagePrompt' => '   ',
                'variantGroupId' => '   ',
                'variantSentenceId' => "\t",
                'variantKind' => '   ',
                'variantStage' => null,
                'variantStatus' => "\n",
                'variantUnlockedAt' => '   ',
            ])
            ->assertCreated()
            ->assertJsonPath('creationKind', StudyCardCreationKind::ProductionImage->value)
            ->assertJsonPath('cardType', CardType::Production->value)
            ->assertJsonPath('prompt.cueText', '  company  ')
            ->assertJsonPath('answer.meaning', '  会社  ')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('imagePrompt', null)
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame(['cueText' => '  company  '], $draft->prompt_json);
        $this->assertSame(['meaning' => '  会社  '], $draft->answer_json);
        $this->assertNull($draft->image_prompt);
        $this->assertNull($draft->variant_group_id);
        $this->assertNull($draft->variant_sentence_id);
        $this->assertNull($draft->variant_kind);
        $this->assertNull($draft->variant_stage);
        $this->assertNull($draft->variant_status);
        $this->assertNull($draft->variant_unlocked_at);

        // This test intentionally posts twice: first for payload normalization, then for defaults.
        StudyCardDraft::query()->delete();

        $defaultResponse = $this
            ->postJson('/api/study/card-drafts', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => 'front'],
                'answer' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($defaultResponse->json());

        Queue::assertPushed(ProcessStudyCardDraft::class, 2);
    }
}
