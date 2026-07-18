<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CreateStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\ProcessStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\RetryStudyVocabBundleDraftsAction;
use App\Domain\Study\Data\CreateStudyVocabBundleData;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Models\StudyVocabVariantSentence;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Jobs\ProcessStudyCardDraft;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessStudyVocabBundleDraftsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_atomically_fills_all_vocab_bundle_drafts(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->generatedBundle(), JSON_THROW_ON_ERROR),
            ]),
        ]);

        $group = $this->createBundle('この会社で働いています。');
        $initialSyncCount = SyncFeedEntry::query()->count();

        $updated = app(ProcessStudyVocabBundleDraftsAction::class)->handle($group->id);

        $this->assertSame(11, $updated);
        $group->refresh();
        $this->assertSame('会社', $group->target_word);
        $this->assertSame('会社[かいしゃ]', $group->target_reading);
        $this->assertSame('company', $group->target_meaning);

        $sentences = StudyVocabVariantSentence::query()->orderBy('ordinal')->get();
        $this->assertSame('この会社で働いています。', $sentences[0]->sentence_jp);
        $this->assertSame('I work at this company.', $sentences[0]->sentence_en);

        $drafts = StudyCardDraft::query()->orderBy('variant_stage')->get();
        $this->assertCount(11, $drafts);
        $this->assertTrue($drafts->every(
            fn (StudyCardDraft $draft): bool => $draft->status === StudyManualCardDraftStatus::Ready
                && $draft->error_message === null
                && $draft->preview_audio_json === null
                && $draft->preview_image_json === null,
        ));

        $sentenceAudio = $drafts->firstWhere('variant_kind', 'sentence_audio_recognition');
        $this->assertNotNull($sentenceAudio);
        $this->assertSame([], $sentenceAudio->prompt_json);
        $this->assertSame('この会社で働いています。', $sentenceAudio->answer_json['expression']);

        $wordText = $drafts->firstWhere('variant_kind', 'word_text_recognition');
        $this->assertNotNull($wordText);
        $this->assertSame('会社', $wordText->prompt_json['cueText']);
        $this->assertSame('company', $wordText->answer_json['meaning']);

        $cloze = $drafts->firstWhere('variant_kind', 'sentence_cloze');
        $this->assertNotNull($cloze);
        $this->assertSame('both', $cloze->image_placement->value);
        $this->assertStringContainsString('I work at this company.', $cloze->image_prompt);
        $this->assertSame($initialSyncCount + 11, SyncFeedEntry::query()->count());
        $updateEntries = SyncFeedEntry::query()
            ->where('checkpoint', '>', $initialSyncCount)
            ->get();
        $this->assertTrue($updateEntries->every(
            fn (SyncFeedEntry $entry): bool => $entry->user_id === $group->user_id
                && $entry->domain === 'study'
                && $entry->resource_type === 'study_card_draft'
                && $entry->operation->value === 'update',
        ));
        $this->assertEqualsCanonicalizing(
            $drafts->pluck('id')->all(),
            $updateEntries->pluck('resource_id')->all(),
        );

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['text']['format']['type'] === 'json_object');
    }

    public function test_it_is_idempotent_after_the_bundle_is_ready(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->generatedBundle(), JSON_THROW_ON_ERROR),
            ]),
        ]);
        $group = $this->createBundle();
        $action = app(ProcessStudyVocabBundleDraftsAction::class);

        $this->assertSame(11, $action->handle($group->id));
        $syncCount = SyncFeedEntry::query()->count();
        $this->assertSame(0, $action->handle(strtolower($group->id)));
        $this->assertSame($syncCount, SyncFeedEntry::query()->count());
        Http::assertSentCount(1);
    }

    public function test_provider_cannot_replace_the_user_requested_target_word(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        $bundle = $this->generatedBundle();
        $bundle['targetWord'] = '学校';
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($bundle, JSON_THROW_ON_ERROR),
            ]),
        ]);
        $group = $this->createBundle();
        $syncCount = SyncFeedEntry::query()->count();

        try {
            app(ProcessStudyVocabBundleDraftsAction::class)->handle($group->id);
            $this->fail('Expected provider target-word drift to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Generated study vocab bundle changed the requested target word.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('会社', $group->fresh()->target_word);
        $this->assertSame(
            11,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->count(),
        );
        $this->assertSame($syncCount, SyncFeedEntry::query()->count());
    }

    public function test_failed_job_marks_only_generating_bundle_drafts_as_errors(): void
    {
        $group = $this->createBundle();
        $readyDraft = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->firstOrFail();
        $readyDraft->status = StudyManualCardDraftStatus::Ready;
        $readyDraft->save();

        $job = new ProcessStudyVocabBundleDrafts(strtolower($group->id));
        $job->failed(new \RuntimeException('provider unavailable'));

        $this->assertSame(
            StudyManualCardDraftStatus::Ready,
            $readyDraft->fresh()->status,
        );
        $failedDrafts = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->where('status', StudyManualCardDraftStatus::Error)
            ->get();
        $this->assertCount(10, $failedDrafts);
        $this->assertTrue($failedDrafts->every(
            fn (StudyCardDraft $draft): bool => $draft->error_message
                === ProcessStudyVocabBundleDrafts::EXHAUSTED_ERROR_MESSAGE,
        ));
        $syncCount = SyncFeedEntry::query()->count();

        $job->failed(new \RuntimeException('duplicate failure callback'));

        $this->assertSame($syncCount, SyncFeedEntry::query()->count());
    }

    public function test_retrying_one_failed_member_resets_and_requeues_the_whole_bundle(): void
    {
        Queue::fake();
        $group = $this->createBundle();
        $job = new ProcessStudyVocabBundleDrafts($group->id);
        $job->failed(new \RuntimeException('provider unavailable'));
        $failedDraft = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->firstOrFail();

        $retried = app(RetryStudyVocabBundleDraftsAction::class)->handleIfBundle(
            $group->user_id,
            strtoupper($failedDraft->id),
            afterCommit: static fn (string $groupId) => ProcessStudyVocabBundleDrafts::dispatch($groupId),
        );

        $this->assertNotNull($retried);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $retried->status);
        $this->assertSame(
            11,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->count(),
        );
        Queue::assertPushedOn(
            ProcessStudyVocabBundleDrafts::QUEUE_NAME,
            ProcessStudyVocabBundleDrafts::class,
            fn (ProcessStudyVocabBundleDrafts $queued): bool => $queued->groupId === $group->id,
        );
        Queue::assertNotPushed(ProcessStudyCardDraft::class);
    }

    private function createBundle(?string $sourceSentence = null): StudyVocabVariantGroup
    {
        $user = User::factory()->create();
        $result = app(CreateStudyVocabBundleDraftsAction::class)->handle(
            CreateStudyVocabBundleData::fromInput(
                userId: $user->id,
                targetWord: '会社',
                sourceSentence: $sourceSentence,
                context: 'workplace vocabulary',
                includeLearnerContext: false,
            ),
        );

        return $result->group;
    }

    /** @return array<string, mixed> */
    private function generatedBundle(): array
    {
        return [
            'targetWord' => '会社',
            'targetReading' => '会社[かいしゃ]',
            'targetMeaning' => 'company',
            'sentences' => [
                [
                    'sentenceJp' => 'この会社で働いています。',
                    'sentenceReading' => 'この会社[かいしゃ]で働[はたら]いています。',
                    'sentenceEn' => 'I work at this company.',
                    'clozeText' => 'この{{c1::会社}}で働いています。',
                    'clozeHint' => 'company',
                    'notes' => 'A common workplace phrase.',
                ],
                [
                    'sentenceJp' => '会社は駅の近くです。',
                    'sentenceReading' => '会社[かいしゃ]は駅[えき]の近[ちか]くです。',
                    'sentenceEn' => 'The company is near the station.',
                    'clozeText' => '{{c1::会社}}は駅の近くです。',
                    'clozeHint' => 'company',
                    'notes' => null,
                ],
                [
                    'sentenceJp' => '新しい会社を探しています。',
                    'sentenceReading' => '新[あたら]しい会社[かいしゃ]を探[さが]しています。',
                    'sentenceEn' => 'I am looking for a new company.',
                    'clozeText' => '新しい{{c1::会社}}を探しています。',
                    'clozeHint' => 'company',
                    'notes' => 'Used while job hunting.',
                ],
            ],
        ];
    }
}
