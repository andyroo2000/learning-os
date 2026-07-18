<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Data\CreateStudyVocabBundleData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Models\StudyVocabVariantSentence;
use App\Domain\Study\Services\StudyVocabBundleGenerator;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreStudyVocabBundleDraftsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
            'targetWord' => '勉強',
        ])->assertUnauthorized();
    }

    public function test_route_uses_the_dedicated_vocab_bundle_rate_limiter(): void
    {
        $route = app('router')->getRoutes()->match(Request::create(
            '/api/study/card-candidates/vocab-bundle/drafts',
            'POST',
        ));

        $this->assertContains('throttle:study-vocab-bundle-drafts', $route->gatherMiddleware());
    }

    public function test_it_creates_a_complete_placeholder_bundle_and_dispatches_one_job(): void
    {
        Queue::fake();
        $user = $this->signIn();

        $response = $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
            'targetWord' => ' 勉強 ',
            'sourceSentence' => ' 毎日、日本語を勉強します。 ',
            'context' => ' JLPT N4 ',
            'includeLearnerContext' => '0',
        ])
            ->assertCreated()
            ->assertJsonCount(StudyVocabBundleGenerator::DRAFT_COUNT, 'drafts')
            ->assertJsonPath('drafts.0.status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('drafts.0.variantKind', 'sentence_audio_recognition')
            ->assertJsonPath('drafts.0.variantStage', 1)
            ->assertJsonPath('drafts.0.variantStatus', 'available')
            ->assertJsonPath('drafts.10.variantKind', 'sentence_cloze')
            ->assertJsonPath('drafts.10.variantStage', 5)
            ->assertJsonPath('drafts.10.variantStatus', 'locked');

        $group = StudyVocabVariantGroup::query()->sole();
        $this->assertSame($user->id, $group->user_id);
        $this->assertSame('勉強', $group->target_word);
        $this->assertSame('毎日、日本語を勉強します。', $group->source_sentence);
        $this->assertSame('JLPT N4', $group->source_context);
        $this->assertFalse($group->include_learner_context);
        $this->assertSame($group->id, $response->json('groupId'));

        $sentences = StudyVocabVariantSentence::query()->orderBy('ordinal')->get();
        $this->assertCount(3, $sentences);
        $this->assertSame('毎日、日本語を勉強します。', $sentences[0]->sentence_jp);

        $drafts = StudyCardDraft::query()
            ->orderBy('variant_stage')
            ->orderBy('variant_sentence_id')
            ->get();
        $this->assertCount(StudyVocabBundleGenerator::DRAFT_COUNT, $drafts);
        $this->assertTrue($drafts->every(
            fn (StudyCardDraft $draft): bool => $draft->user_id === $user->id
                && $draft->variant_group_id === $group->id
                && $draft->status === StudyManualCardDraftStatus::Generating,
        ));
        $entries = SyncFeedEntry::query()->get();
        $this->assertCount(11, $entries);
        $this->assertTrue($entries->every(
            fn (SyncFeedEntry $entry): bool => $entry->user_id === $user->id
                && $entry->domain === 'study'
                && $entry->resource_type === 'study_card_draft',
        ));
        $this->assertEqualsCanonicalizing(
            $drafts->pluck('id')->all(),
            $entries->pluck('resource_id')->all(),
        );

        Queue::assertPushedOn(
            ProcessStudyVocabBundleDrafts::QUEUE_NAME,
            ProcessStudyVocabBundleDrafts::class,
            fn (ProcessStudyVocabBundleDrafts $job): bool => $job->groupId === $group->id,
        );
    }

    public function test_it_normalizes_defaults_and_validates_bounds(): void
    {
        Queue::fake();
        $this->signIn();

        $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
            'targetWord' => '会社',
            'sourceSentence' => '   ',
            'context' => null,
        ])
            ->assertCreated();

        $group = StudyVocabVariantGroup::query()->sole();
        $this->assertNull($group->source_sentence);
        $this->assertNull($group->source_context);
        $this->assertTrue($group->include_learner_context);

        $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
            'targetWord' => '',
            'sourceSentence' => str_repeat('a', CreateStudyVocabBundleData::MAX_SOURCE_SENTENCE_LENGTH + 1),
            'context' => str_repeat('b', CreateStudyVocabBundleData::MAX_CONTEXT_LENGTH + 1),
            'includeLearnerContext' => 'sometimes',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'targetWord',
                'sourceSentence',
                'context',
                'includeLearnerContext',
            ]);
    }

    public function test_request_owns_normalization_when_global_trimming_is_disabled(): void
    {
        Queue::fake();
        $this->signIn();

        $this->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
                'targetWord' => ' 会社 ',
                'sourceSentence' => '   ',
                'context' => ' workplace ',
            ])
            ->assertCreated();

        $group = StudyVocabVariantGroup::query()->sole();
        $this->assertSame('会社', $group->target_word);
        $this->assertNull($group->source_sentence);
        $this->assertSame('workplace', $group->source_context);
        $this->assertTrue($group->include_learner_context);
    }

    public function test_queue_dispatch_failure_returns_retriable_error_drafts_instead_of_stranding_generation(): void
    {
        $this->signIn();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        $response = $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
            'targetWord' => '会社',
        ])
            ->assertCreated()
            ->assertJsonCount(StudyVocabBundleGenerator::DRAFT_COUNT, 'drafts')
            ->assertJsonPath('drafts.0.status', StudyManualCardDraftStatus::Error->value)
            ->assertJsonPath(
                'drafts.0.errorMessage',
                ProcessStudyVocabBundleDrafts::EXHAUSTED_ERROR_MESSAGE,
            );

        $this->assertSame(
            array_fill(0, StudyVocabBundleGenerator::DRAFT_COUNT, StudyManualCardDraftStatus::Error->value),
            array_column($response->json('drafts'), 'status'),
        );
        $this->assertSame(
            0,
            StudyCardDraft::query()
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->count(),
        );
    }
}
