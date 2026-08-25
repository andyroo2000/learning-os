<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Actions\DispatchWaniKaniTransferImportsAction;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Study\Actions\CommitAutomaticStudyVocabBundleAction;
use App\Domain\Study\Actions\PrepareStudyCardAnswerAudioAction;
use App\Domain\Study\Actions\ProcessStudyVocabBundleDraftsAction;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Services\StudyVocabBundleGenerator;
use App\Domain\Study\Support\StudyCardAudioRecognition;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WaniKaniTransferImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-25T12:00:00Z');
    }

    public function test_it_selects_the_oldest_visible_recent_vocabulary_with_a_daily_cap(): void
    {
        $this->assertTrue(
            collect(Schema::getIndexes('study_card_drafts'))
                ->contains('name', 'study_card_drafts_user_variant_group_idx'),
        );
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user, enabled: true);
        $this->vocabulary($user, 101, '会社', now()->subHour(), ['かいしゃ'], ['Company']);
        $this->vocabulary($user, 102, '橋', now()->subMinutes(30), ['はし'], ['Bridge']);
        $this->vocabulary($user, 103, '予約', now()->subMinutes(15), ['よやく'], ['Reservation']);
        $this->vocabulary($user, 104, '古い', now()->subHours(25));
        $this->vocabulary($user, 105, '秘密', now()->subMinutes(45), hidden: true);
        $this->vocabulary($user, 106, '隠語', now()->subMinutes(40), subjectHidden: true);

        $action = app(DispatchWaniKaniTransferImportsAction::class);

        $this->assertSame(['created' => 2, 'retried' => 0], $action->handle($user->id));
        $this->assertSame(
            [101, 102],
            StudyVocabVariantGroup::query()->orderBy('wanikani_subject_id')->pluck('wanikani_subject_id')->all(),
        );
        $this->assertDatabaseCount('study_card_drafts', 2 * StudyVocabBundleGenerator::DRAFT_COUNT);
        $company = StudyVocabVariantGroup::query()->where('wanikani_subject_id', 101)->sole();
        $this->assertStringContainsString('WaniKani reading: かいしゃ', $company->source_context);
        $this->assertStringContainsString('Meaning: Company', $company->source_context);
        $this->assertSame(['created' => 0, 'retried' => 0], $action->handle($user->id));

        CarbonImmutable::setTestNow('2026-08-26T12:00:00Z');
        $this->assertSame(['created' => 1, 'retried' => 0], $action->handle($user->id));
        $this->assertSame(
            [101, 102, 103],
            StudyVocabVariantGroup::query()->orderBy('wanikani_subject_id')->pluck('wanikani_subject_id')->all(),
        );
        Queue::assertPushed(ProcessStudyVocabBundleDrafts::class, 3);
    }

    public function test_enabling_the_bridge_immediately_queues_already_synced_recent_vocabulary(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $this->connection($user);
        $this->vocabulary($user, 201, '会議', now()->subHour(), ['かいぎ'], ['Meeting']);

        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('wanikani.transferBridge.enabled', true)
            ->assertJsonPath('wanikani.transferBridge.pendingVocabularyCount', 1);

        $this->assertDatabaseHas('study_vocab_variant_groups', [
            'user_id' => $user->id,
            'wanikani_subject_id' => 201,
            'automatic_import_status' => AutomaticStudyVocabImportStatus::Generating->value,
        ]);
        Queue::assertPushed(ProcessStudyVocabBundleDrafts::class, 1);
    }

    public function test_the_generation_job_commits_fourteen_cards_and_prepares_listening_audio_idempotently(): void
    {
        Queue::fake();
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->generatedBundle(), JSON_THROW_ON_ERROR),
            ]),
        ]);
        $user = User::factory()->create();
        $connection = $this->connection($user, enabled: true);
        $this->vocabulary($user, 301, '会社', now()->subHour(), ['かいしゃ'], ['Company']);
        app(DispatchWaniKaniTransferImportsAction::class)->handle($user->id);
        $group = StudyVocabVariantGroup::query()->where('wanikani_subject_id', 301)->sole();
        $draftIds = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $this->mock(PrepareStudyCardAnswerAudioAction::class)
            ->shouldReceive('handle')
            ->times(4)
            ->andReturnUsing(static fn (Card $card): Card => $card);
        $job = new ProcessStudyVocabBundleDrafts($group->id);

        $job->handle(
            app(ProcessStudyVocabBundleDraftsAction::class),
            app(CommitAutomaticStudyVocabBundleAction::class),
        );

        $this->assertSame(
            $draftIds,
            Card::query()->where('variant_group_id', $group->id)->pluck('id')->sort()->values()->all(),
        );
        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertSame(StudyVocabBundleGenerator::DRAFT_COUNT, Card::query()->where('variant_group_id', $group->id)->count());
        $this->assertSame(3, Card::query()->where('variant_group_id', $group->id)->where('variant_kind', VocabVariantKind::SentenceProduction->value)->count());
        $this->assertSame(AutomaticStudyVocabImportStatus::Imported, $group->fresh()->automatic_import_status);
        $this->assertTrue($group->fresh()->automatic_imported_at->equalTo(now()));
        $this->assertTrue($connection->fresh()->transfer_bridge_last_imported_at->equalTo(now()));

        $job->handle(
            app(ProcessStudyVocabBundleDraftsAction::class),
            app(CommitAutomaticStudyVocabBundleAction::class),
        );
        $this->assertSame(StudyVocabBundleGenerator::DRAFT_COUNT, Card::query()->where('variant_group_id', $group->id)->count());
        Http::assertSentCount(1);
    }

    public function test_exhausted_generation_is_recorded_and_retried_as_one_bundle(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->connection($user, enabled: true);
        $this->vocabulary($user, 401, '練習', now()->subHour(), ['れんしゅう'], ['Practice']);
        $action = app(DispatchWaniKaniTransferImportsAction::class);
        $action->handle($user->id);
        $group = StudyVocabVariantGroup::query()->where('wanikani_subject_id', 401)->sole();

        (new ProcessStudyVocabBundleDrafts($group->id))
            ->failed(new \RuntimeException('provider unavailable'));

        $this->assertSame(AutomaticStudyVocabImportStatus::Error, $group->fresh()->automatic_import_status);
        $this->assertSame(
            StudyVocabBundleGenerator::DRAFT_COUNT,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Error)
                ->count(),
        );

        // This test invokes failed() directly, so release the unique-job cache lock that a
        // real queue worker releases after exhausting the job.
        Cache::lock(UniqueLock::getKey(new ProcessStudyVocabBundleDrafts($group->id)))->forceRelease();
        $this->assertSame(['created' => 0, 'retried' => 1], $action->handle($user->id));
        $this->assertSame(AutomaticStudyVocabImportStatus::Generating, $group->fresh()->automatic_import_status);
        $this->assertNull($group->fresh()->automatic_import_error);
        $this->assertSame(
            StudyVocabBundleGenerator::DRAFT_COUNT,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->count(),
        );
        Queue::assertPushed(ProcessStudyVocabBundleDrafts::class, 2);
    }

    public function test_queue_failure_leaves_a_durable_retriable_import_error(): void
    {
        Exceptions::fake();
        $user = User::factory()->create();
        $this->connection($user, enabled: true);
        $this->vocabulary($user, 450, '復習', now()->subHour(), ['ふくしゅう'], ['Review']);
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        $this->assertSame(
            ['created' => 0, 'retried' => 0],
            app(DispatchWaniKaniTransferImportsAction::class)->handle($user->id),
        );

        $group = StudyVocabVariantGroup::query()->where('wanikani_subject_id', 450)->sole();
        $this->assertSame(AutomaticStudyVocabImportStatus::Error, $group->automatic_import_status);
        $this->assertSame('Could not queue this automatic vocabulary import.', $group->automatic_import_error);
        $this->assertSame(
            StudyVocabBundleGenerator::DRAFT_COUNT,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Error)
                ->count(),
        );
        Exceptions::assertReported(
            fn (\RuntimeException $exception): bool => $exception->getMessage() === 'queue unavailable',
        );
    }

    public function test_commit_recovery_reuses_a_card_created_before_audio_failed(): void
    {
        Queue::fake();
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode($this->generatedBundle(), JSON_THROW_ON_ERROR),
            ]),
        ]);
        $user = User::factory()->create();
        $this->connection($user, enabled: true);
        $this->vocabulary($user, 501, '会社', now()->subHour(), ['かいしゃ'], ['Company']);
        app(DispatchWaniKaniTransferImportsAction::class)->handle($user->id);
        $group = StudyVocabVariantGroup::query()->where('wanikani_subject_id', 501)->sole();
        app(ProcessStudyVocabBundleDraftsAction::class)->handle($group->id);
        $audioCalls = 0;
        $this->mock(PrepareStudyCardAnswerAudioAction::class)
            ->shouldReceive('handle')
            ->times(5)
            ->andReturnUsing(static function (Card $card) use (&$audioCalls): Card {
                $audioCalls++;
                if ($audioCalls === 1) {
                    throw new \RuntimeException('speech provider unavailable');
                }

                return $card;
            });
        $commit = app(CommitAutomaticStudyVocabBundleAction::class);

        try {
            $commit->handle($group->id);
            $this->fail('Expected the first speech preparation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('speech provider unavailable', $exception->getMessage());
        }

        $this->assertSame(1, Card::query()->where('variant_group_id', $group->id)->count());
        $this->assertSame(StudyVocabBundleGenerator::DRAFT_COUNT, StudyCardDraft::query()->where('variant_group_id', $group->id)->count());
        $this->assertNotNull(StudyCardDraft::query()->where('variant_group_id', $group->id)->firstOrFail()->committed_card_id);

        $this->assertSame(StudyVocabBundleGenerator::DRAFT_COUNT, $commit->handle($group->id));
        $this->assertSame(StudyVocabBundleGenerator::DRAFT_COUNT, Card::query()->where('variant_group_id', $group->id)->count());
        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertSame(AutomaticStudyVocabImportStatus::Imported, $group->fresh()->automatic_import_status);
    }

    public function test_audio_variants_are_recognized_before_their_first_cue_audio_is_generated(): void
    {
        $card = new Card;
        $card->card_type = CardType::Recognition;
        $card->variant_kind = VocabVariantKind::SentenceAudioRecognition->value;

        $this->assertTrue(StudyCardAudioRecognition::hasAudioOnlyPrompt($card, []));

        $card->variant_kind = VocabVariantKind::SentenceTextRecognition->value;
        $this->assertFalse(StudyCardAudioRecognition::hasAudioOnlyPrompt($card, []));

        $card->variant_kind = VocabVariantKind::SentenceAudioRecognition->value;
        $this->assertFalse(StudyCardAudioRecognition::hasAudioOnlyPrompt($card, ['cueText' => '会社']));
    }

    private function connection(User $user, bool $enabled = false): WaniKaniConnection
    {
        $connection = new WaniKaniConnection;
        $connection->user_id = $user->id;
        $connection->api_token = 'test-token';
        $connection->transfer_bridge_enabled = $enabled;
        $connection->transfer_bridge_enabled_at = $enabled ? now() : null;
        $connection->save();

        return $connection;
    }

    /** @param list<string> $readings @param list<string> $meanings */
    private function vocabulary(
        User $user,
        int $subjectId,
        string $characters,
        mixed $passedAt,
        array $readings = ['ふるい'],
        array $meanings = ['Old'],
        bool $hidden = false,
        bool $subjectHidden = false,
    ): void {
        $now = now();
        DB::table('wanikani_subjects')->insert([
            'subject_id' => $subjectId,
            'subject_type' => 'vocabulary',
            'characters' => $characters,
            'normalized_key' => $characters,
            'readings' => json_encode($readings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'meanings' => json_encode($meanings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'hidden_at' => $subjectHidden ? $now : null,
            'source_updated_at' => $now,
            'matcher_version' => 'test-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_wanikani_assignments')->insert([
            'user_id' => $user->id,
            'subject_id' => $subjectId,
            'srs_stage' => 5,
            'passed_at' => $passedAt,
            'burned_at' => null,
            'hidden' => $hidden,
            'source_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
