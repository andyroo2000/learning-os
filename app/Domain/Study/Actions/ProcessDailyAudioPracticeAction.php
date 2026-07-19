<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Services\DailyAudioDrillScriptGenerator;
use App\Domain\Study\Services\DailyAudioTrackAssembler;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessDailyAudioPracticeAction
{
    public function __construct(
        private readonly SelectDailyAudioPracticeCardsAction $selectCards,
        private readonly BuildDailyAudioLearningAtomsAction $buildAtoms,
        private readonly DailyAudioDrillScriptGenerator $scriptGenerator,
        private readonly DailyAudioTrackAssembler $trackAssembler,
    ) {}

    public function handle(string $practiceId): void
    {
        $practiceId = strtolower(trim($practiceId));
        if (! DailyAudioPracticeId::isValid($practiceId)) {
            return;
        }

        $claimed = $this->claimGeneration($practiceId);
        if ($claimed === null) {
            return;
        }

        $practice = $claimed['practice'];
        $track = $claimed['track'];
        $selected = $this->selectCards->handle($practice->user_id);
        $atoms = $this->buildAtoms->handle($selected->cards);
        if ($atoms->isEmpty()) {
            throw new InvalidArgumentException(
                DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE,
            );
        }

        $this->storeSelection($practice, $selected->clientCardIds(), $selected->summary);

        $generated = $this->scriptGenerator->generate(
            $atoms,
            (string) config('daily_audio.l1_voice_id'),
            (string) config('daily_audio.l2_voice_id'),
        );
        $assembled = $this->trackAssembler->assemble(
            $practice->id,
            $track->id,
            $generated->units,
        );

        DB::transaction(function () use ($assembled, $atoms, $generated, $practice, $track): void {
            $lockedPractice = DailyAudioPractice::query()
                ->whereKey($practice->id)
                ->lockForUpdate()
                ->first();
            if ($lockedPractice === null || $lockedPractice->status !== 'generating') {
                return;
            }

            DailyAudioPracticeTrack::query()
                ->whereKey($track->id)
                ->where('practice_id', $practice->id)
                ->update([
                    'status' => 'ready',
                    'script_units_json' => $generated->scriptUnits(),
                    'audio_url' => DailyAudioPracticeGeneration::audioUrl($practice->id, $track->id),
                    'timing_data' => $assembled->timingData,
                    'approx_duration_seconds' => $assembled->durationSeconds,
                    'generation_metadata_json' => [
                        'sourceCardCount' => $atoms->count(),
                        ...$generated->metadata,
                        ...$assembled->metadata,
                    ],
                    'error_message' => null,
                ]);

            $lockedPractice->status = 'ready';
            $lockedPractice->error_message = null;
            $lockedPractice->save();
        });
    }

    /**
     * @return null|array{practice: DailyAudioPractice, track: DailyAudioPracticeTrack}
     */
    private function claimGeneration(string $practiceId): ?array
    {
        return DB::transaction(function () use ($practiceId): ?array {
            $lockedPractice = DailyAudioPractice::query()
                ->whereKey($practiceId)
                ->lockForUpdate()
                ->first();

            if ($lockedPractice === null || $lockedPractice->status !== 'generating') {
                return null;
            }

            $track = DailyAudioPracticeTrack::query()
                ->where('practice_id', $lockedPractice->id)
                ->where('mode', 'drill')
                ->lockForUpdate()
                ->sole();
            $track->status = 'generating';
            $track->error_message = null;
            $track->save();

            return [
                'practice' => $lockedPractice,
                'track' => $track,
            ];
        });
    }

    /**
     * @param  list<string>  $cardIds
     * @param  array<string, int>  $summary
     */
    private function storeSelection(
        DailyAudioPractice $practice,
        array $cardIds,
        array $summary,
    ): void {
        DailyAudioPractice::query()
            ->whereKey($practice->id)
            ->where('status', 'generating')
            ->update([
                'source_card_ids_json' => $cardIds,
                'selection_summary_json' => $summary,
            ]);
    }
}
