<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Services\DailyAudioContextTrackGenerator;
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
        private readonly DailyAudioContextTrackGenerator $contextTrackGenerator,
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
        $tracks = $claimed['tracks'];
        $selected = $this->selectCards->handle($practice->user_id);
        $atoms = $this->buildAtoms->handle($selected->cards);
        if ($atoms->isEmpty()) {
            throw new InvalidArgumentException(
                DailyAudioPracticeGeneration::NO_ELIGIBLE_CARDS_MESSAGE,
            );
        }

        $this->storeSelection($practice, $selected->clientCardIds(), $selected->summary);

        $l1VoiceId = (string) config('daily_audio.l1_voice_id');
        $l2VoiceId = (string) config('daily_audio.l2_voice_id');
        $dialogueSpeakerAVoiceId = (string) config(
            'daily_audio.dialogue_speaker_a_voice_id',
        );
        $dialogueSpeakerBVoiceId = (string) config(
            'daily_audio.dialogue_speaker_b_voice_id',
        );
        $generated = [
            'drill' => $this->scriptGenerator->generate(
                $atoms,
                $l1VoiceId,
                $l2VoiceId,
            ),
            'dialogue' => $this->contextTrackGenerator->generateDialogue(
                $atoms,
                $l1VoiceId,
                $dialogueSpeakerAVoiceId,
                $dialogueSpeakerBVoiceId,
            ),
            'story' => $this->contextTrackGenerator->generateStory(
                $atoms,
                $l1VoiceId,
                $l2VoiceId,
            ),
        ];
        $assembled = [];
        foreach ($generated as $mode => $trackGeneration) {
            $track = $tracks[$mode];
            $assembled[$mode] = $this->trackAssembler->assemble(
                $practice->id,
                $track->id,
                $trackGeneration->units,
            );
        }

        DB::transaction(function () use ($assembled, $atoms, $generated, $practice, $tracks): void {
            $lockedPractice = DailyAudioPractice::query()
                ->whereKey($practice->id)
                ->lockForUpdate()
                ->first();
            if ($lockedPractice === null || $lockedPractice->status !== 'generating') {
                return;
            }

            foreach ($generated as $mode => $trackGeneration) {
                $track = $tracks[$mode];
                $trackAssembly = $assembled[$mode];
                DailyAudioPracticeTrack::query()
                    ->whereKey($track->id)
                    ->where('practice_id', $practice->id)
                    ->where('mode', $mode)
                    ->update([
                        'status' => 'ready',
                        'script_units_json' => $trackGeneration->scriptUnits(),
                        'audio_url' => DailyAudioPracticeGeneration::audioUrl($practice->id, $track->id),
                        'timing_data' => $trackAssembly->timingData,
                        'approx_duration_seconds' => $trackAssembly->durationSeconds,
                        'generation_metadata_json' => [
                            'sourceCardCount' => $atoms->count(),
                            ...$trackGeneration->metadata,
                            ...$trackAssembly->metadata,
                        ],
                        'error_message' => null,
                    ]);
            }

            $lockedPractice->status = 'ready';
            $lockedPractice->error_message = null;
            $lockedPractice->save();
        });
    }

    /**
     * @return null|array{
     *     practice: DailyAudioPractice,
     *     tracks: array<string, DailyAudioPracticeTrack>
     * }
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

            $tracks = DailyAudioPracticeTrack::query()
                ->where('practice_id', $lockedPractice->id)
                ->whereIn('mode', ['drill', 'dialogue', 'story'])
                ->lockForUpdate()
                ->get()
                ->keyBy('mode');

            foreach (['drill', 'dialogue', 'story'] as $mode) {
                $track = $tracks->get($mode);
                if (! $track instanceof DailyAudioPracticeTrack) {
                    throw new InvalidArgumentException(
                        "Daily Audio Practice is missing its {$mode} track.",
                    );
                }
                $track->status = 'generating';
                $track->error_message = null;
                $track->save();
            }

            return [
                'practice' => $lockedPractice,
                'tracks' => $tracks->all(),
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
