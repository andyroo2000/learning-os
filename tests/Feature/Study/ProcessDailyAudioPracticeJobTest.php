<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\BuildDailyAudioLearningAtomsAction;
use App\Domain\Study\Actions\FailDailyAudioPracticeAction;
use App\Domain\Study\Actions\ProcessDailyAudioPracticeAction;
use App\Domain\Study\Actions\SelectDailyAudioPracticeCardsAction;
use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Results\DailyAudioCardSelectionResult;
use App\Domain\Study\Results\DailyAudioDrillGenerationResult;
use App\Domain\Study\Results\DailyAudioLearningAtom;
use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Results\DailyAudioTrackAssemblyResult;
use App\Domain\Study\Services\DailyAudioDrillScriptGenerator;
use App\Domain\Study\Services\DailyAudioTrackAssembler;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Jobs\ProcessDailyAudioPractice;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ProcessDailyAudioPracticeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_a_bounded_retry_timeout_and_uniqueness_envelope(): void
    {
        $practiceId = '33CB3D35-8566-4DD5-AEBE-AF1725C3D18A';
        $job = new ProcessDailyAudioPractice("  {$practiceId}  ");

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(strtolower($practiceId), $job->practiceId);
        $this->assertSame(2, $job->tries);
        $this->assertSame(3500, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([30], $job->backoff());
        $this->assertSame(strtolower($practiceId), $job->uniqueId());
        $this->assertSame('default', $job->queue);
    }

    public function test_it_rejects_malformed_job_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcessDailyAudioPractice('not-a-uuid');
    }

    public function test_it_generates_and_persists_the_drill_track(): void
    {
        $practice = DailyAudioPractice::factory()->create([
            'status' => 'generating',
        ]);
        $drill = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'status' => 'draft',
            'audio_url' => null,
        ]);
        foreach ([
            ['dialogue', 1],
            ['story', 2],
        ] as [$mode, $sortOrder]) {
            DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
                'mode' => $mode,
                'sort_order' => $sortOrder,
                'status' => 'skipped',
            ]);
        }
        $card = Card::factory()->create();
        $selection = new DailyAudioCardSelectionResult(
            cards: collect([$card]),
            summary: [
                'totalCandidates' => 1,
                'selectedCount' => 1,
                'dueCount' => 1,
                'learningCount' => 0,
                'recentMissCount' => 0,
            ],
        );
        $atom = new DailyAudioLearningAtom(
            cardId: $card->clientId(),
            cardType: 'recognition',
            targetText: '猫',
            reading: 'ねこ',
            english: 'cat',
            exampleJp: null,
            exampleEn: null,
            deckName: null,
            noteType: null,
        );
        $units = collect([
            DailyAudioScriptUnit::narration('Welcome.', 'fishaudio:narrator'),
            DailyAudioScriptUnit::pause(1),
            DailyAudioScriptUnit::targetLanguage(
                '猫',
                'ねこ',
                'cat',
                'fishaudio:speaker',
                1.0,
            ),
        ]);
        $generated = new DailyAudioDrillGenerationResult($units, [
            'enhancedAtomCount' => 1,
            'generatedPromptCount' => 1,
            'fallbackPromptCount' => 0,
            'missingCueCount' => 0,
            'totalPromptCount' => 1,
            'unitCount' => 3,
            'l2UnitCount' => 1,
            'l2UnitsWithReadingCount' => 1,
            'l2UnitsMissingReadingCount' => 0,
        ]);
        $assembled = new DailyAudioTrackAssemblyResult(
            storagePath: DailyAudioPracticeGeneration::storagePath($practice->id, $drill->id),
            durationSeconds: 123,
            timingData: [
                ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1_000],
                ['unitIndex' => 1, 'startTime' => 1_000, 'endTime' => 2_000],
                ['unitIndex' => 2, 'startTime' => 2_000, 'endTime' => 3_000],
            ],
            metadata: [
                'unitCount' => 3,
                'spokenUnitCount' => 2,
                'pauseUnitCount' => 1,
                'uniqueSynthesisCount' => 2,
                'reusedSynthesisCount' => 0,
            ],
        );

        $this->mock(SelectDailyAudioPracticeCardsAction::class, function (MockInterface $mock) use ($practice, $selection): void {
            $mock->shouldReceive('handle')->once()->with($practice->user_id)->andReturn($selection);
        });
        $this->mock(BuildDailyAudioLearningAtomsAction::class, function (MockInterface $mock) use ($card, $atom): void {
            $mock->shouldReceive('handle')->once()->withArgs(
                fn ($cards): bool => $cards->sole()->is($card),
            )->andReturn(collect([$atom]));
        });
        $this->mock(DailyAudioDrillScriptGenerator::class, function (MockInterface $mock) use ($generated): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(fn ($atoms, $l1, $l2): bool => $atoms->count() === 1
                    && $l1 === config('daily_audio.l1_voice_id')
                    && $l2 === config('daily_audio.l2_voice_id'))
                ->andReturn($generated);
        });
        $this->mock(DailyAudioTrackAssembler::class, function (MockInterface $mock) use ($assembled, $drill, $practice): void {
            $mock->shouldReceive('assemble')
                ->once()
                ->withArgs(fn ($practiceId, $trackId, $units): bool => $practiceId === $practice->id
                    && $trackId === $drill->id
                    && $units->count() === 3)
                ->andReturn($assembled);
        });

        (new ProcessDailyAudioPractice($practice->id))
            ->handle(app(ProcessDailyAudioPracticeAction::class));

        $practice->refresh();
        $drill->refresh();
        $this->assertSame('ready', $practice->status);
        $this->assertNull($practice->error_message);
        $this->assertSame([$card->clientId()], $practice->source_card_ids_json);
        $this->assertSame($selection->summary, $practice->selection_summary_json);
        $this->assertSame('ready', $drill->status);
        $this->assertSame(
            DailyAudioPracticeGeneration::audioUrl($practice->id, $drill->id),
            $drill->audio_url,
        );
        $this->assertSame(123, $drill->approx_duration_seconds);
        $this->assertSame(3, $drill->generation_metadata_json['unitCount']);
        $this->assertSame(1, $drill->generation_metadata_json['sourceCardCount']);
        $this->assertSame(2, $drill->generation_metadata_json['spokenUnitCount']);
        $this->assertSame('猫', $drill->script_units_json[2]['text']);
    }

    public function test_processor_ignores_missing_and_terminal_practices(): void
    {
        $ready = DailyAudioPractice::factory()->create([
            'status' => 'ready',
            'error_message' => 'Keep marker.',
        ]);
        $originalUpdatedAt = $ready->updated_at?->toJSON();
        $process = app(ProcessDailyAudioPracticeAction::class);

        $process->handle('33cb3d35-8566-4dd5-aebe-af1725c3d18a');
        $process->handle($ready->id);

        $ready->refresh();
        $this->assertSame('ready', $ready->status);
        $this->assertSame('Keep marker.', $ready->error_message);
        $this->assertSame($originalUpdatedAt, $ready->updated_at?->toJSON());
    }

    public function test_processor_ignores_error_practices(): void
    {
        $error = DailyAudioPractice::factory()->create([
            'status' => 'error',
            'error_message' => 'Keep failure.',
        ]);
        $drill = DailyAudioPracticeTrack::factory()->for($error, 'practice')->create([
            'status' => 'error',
            'error_message' => 'Keep track failure.',
        ]);

        app(ProcessDailyAudioPracticeAction::class)->handle($error->id);

        $this->assertSame('error', $error->refresh()->status);
        $this->assertSame('Keep failure.', $error->error_message);
        $this->assertSame('error', $drill->refresh()->status);
        $this->assertSame('Keep track failure.', $drill->error_message);
    }

    public function test_processor_rejects_malformed_direct_action_ids_before_querying(): void
    {
        app(ProcessDailyAudioPracticeAction::class)->handle('not-a-uuid');

        $this->assertDatabaseCount('daily_audio_practices', 0);
    }

    public function test_processor_rejects_empty_card_selection_before_generation(): void
    {
        $practice = DailyAudioPractice::factory()->create([
            'status' => 'generating',
        ]);
        $drill = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'status' => 'draft',
        ]);
        $selection = new DailyAudioCardSelectionResult(
            cards: collect(),
            summary: [
                'totalCandidates' => 0,
                'selectedCount' => 0,
                'dueCount' => 0,
                'learningCount' => 0,
                'recentMissCount' => 0,
            ],
        );

        $this->mock(SelectDailyAudioPracticeCardsAction::class, function (MockInterface $mock) use ($practice, $selection): void {
            $mock->shouldReceive('handle')->once()->with($practice->user_id)->andReturn($selection);
        });
        $this->mock(BuildDailyAudioLearningAtomsAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(collect());
        });
        $this->mock(DailyAudioDrillScriptGenerator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });
        $this->mock(DailyAudioTrackAssembler::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('assemble');
        });

        try {
            app(ProcessDailyAudioPracticeAction::class)->handle($practice->id);
            $this->fail('Expected generation without eligible cards to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE,
                $exception->getMessage(),
            );
        }

        $this->assertSame('generating', $practice->refresh()->status);
        $this->assertSame('generating', $drill->refresh()->status);
        $this->assertSame([], $practice->source_card_ids_json);
        $this->assertNull($practice->selection_summary_json);
    }

    public function test_failed_callback_marks_only_active_generation_error_and_is_idempotent(): void
    {
        $practice = DailyAudioPractice::factory()->create([
            'status' => 'generating',
        ]);
        $drill = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'status' => 'generating',
        ]);
        $skipped = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'mode' => 'dialogue',
            'sort_order' => 1,
            'status' => 'skipped',
            'error_message' => null,
        ]);
        $job = new ProcessDailyAudioPractice($practice->id);

        $job->failed(new RuntimeException('Provider secret.'));

        $practice->refresh();
        $drill->refresh();
        $this->assertSame('error', $practice->status);
        $this->assertSame(DailyAudioPracticeGeneration::FAILED_MESSAGE, $practice->error_message);
        $this->assertSame('error', $drill->status);
        $this->assertSame(DailyAudioPracticeGeneration::FAILED_MESSAGE, $drill->error_message);
        $this->assertSame('skipped', $skipped->refresh()->status);
        $this->assertNull($skipped->error_message);
        $originalUpdatedAt = $practice->updated_at?->toJSON();

        $job->failed(new RuntimeException('Second provider secret.'));

        $this->assertSame($originalUpdatedAt, $practice->refresh()->updated_at?->toJSON());
    }

    public function test_failed_callback_preserves_the_safe_no_cards_message(): void
    {
        $practice = DailyAudioPractice::factory()->create([
            'status' => 'generating',
        ]);

        (new ProcessDailyAudioPractice($practice->id))->failed(
            new InvalidArgumentException(DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE),
        );

        $this->assertSame(
            DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE,
            $practice->refresh()->error_message,
        );
    }

    public function test_failed_callback_does_not_touch_terminal_or_missing_practices(): void
    {
        $ready = DailyAudioPractice::factory()->create([
            'status' => 'ready',
            'error_message' => 'Keep ready marker.',
        ]);
        $originalUpdatedAt = $ready->updated_at?->toJSON();

        (new ProcessDailyAudioPractice($ready->id))->failed(new RuntimeException('Ignored.'));
        (new ProcessDailyAudioPractice('33cb3d35-8566-4dd5-aebe-af1725c3d18a'))
            ->failed(new RuntimeException('Ignored.'));

        $ready->refresh();
        $this->assertSame('ready', $ready->status);
        $this->assertSame('Keep ready marker.', $ready->error_message);
        $this->assertSame($originalUpdatedAt, $ready->updated_at?->toJSON());
        $this->assertDatabaseCount('daily_audio_practices', 1);
    }

    public function test_failure_action_rejects_malformed_direct_ids_before_querying(): void
    {
        $changed = app(FailDailyAudioPracticeAction::class)->handle(
            'not-a-uuid',
            DailyAudioPracticeGeneration::FAILED_MESSAGE,
        );

        $this->assertFalse($changed);
        $this->assertDatabaseCount('daily_audio_practices', 0);
    }
}
