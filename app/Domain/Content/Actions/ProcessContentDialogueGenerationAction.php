<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Results\ContentDialogueGenerationResult;
use App\Domain\Content\Services\ContentDialogueGenerator;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentDialogueJobId;
use App\Domain\Content\Support\ContentJapaneseMetadata;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ContentSpeakerProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessContentDialogueGenerationAction
{
    public function __construct(private readonly ContentDialogueGenerator $generator) {}

    public function handle(string $jobId): void
    {
        $jobId = ContentDialogueJobId::normalize($jobId);
        $claimed = $this->claim($jobId);
        if ($claimed === null) {
            return;
        }

        try {
            $result = $this->generator->generate($claimed['episode'], $claimed['input']);
            $this->complete($jobId, $result);
        } catch (Throwable $exception) {
            try {
                $this->releaseClaim($jobId);
            } catch (Throwable $releaseException) {
                report($releaseException);
            }

            throw $exception;
        }
    }

    /** @return null|array{episode: array{sourceText: string, targetLanguage: string, nativeLanguage: string, jlptLevel: string|null}, input: GenerateContentDialogueData} */
    private function claim(string $jobId): ?array
    {
        return DB::transaction(function () use ($jobId): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentDialogueGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentDialogueGeneration::isTerminal($job->state)) {
                return null;
            }
            if ($job->state === ContentDialogueGeneration::STATE_ACTIVE
                && $job->started_at !== null
                && $job->started_at->isAfter(
                    now()->subSeconds(ContentDialogueGeneration::ACTIVE_STALE_AFTER_SECONDS),
                )) {
                return null;
            }

            $episode = ContentEpisode::query()->whereKey($job->episode_id)->lockForUpdate()->first();
            if (! $this->ownsAttempt($episode, $job)) {
                $job->state = ContentDialogueGeneration::STATE_FAILED;
                $job->error_message = ContentDialogueGeneration::FAILED_MESSAGE;
                $job->finished_at = now();
                $job->save();

                return null;
            }

            $job->state = ContentDialogueGeneration::STATE_ACTIVE;
            $job->progress = 10;
            $job->started_at = now();
            $job->save();

            return [
                'episode' => [
                    'sourceText' => (string) $episode->source_text,
                    'targetLanguage' => (string) $episode->target_language,
                    'nativeLanguage' => (string) $episode->native_language,
                    'jlptLevel' => is_string($episode->jlpt_level) ? $episode->jlpt_level : null,
                ],
                'input' => GenerateContentDialogueData::fromInput($job->input),
            ];
        });
    }

    private function releaseClaim(string $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentDialogueGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentDialogueGeneration::STATE_ACTIVE) {
                return;
            }

            $job->state = ContentDialogueGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->started_at = null;
            $job->save();
        });
    }

    private function complete(string $jobId, ContentDialogueGenerationResult $result): void
    {
        DB::transaction(function () use ($jobId, $result): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentDialogueGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentDialogueGeneration::STATE_ACTIVE) {
                return;
            }

            $episode = ContentEpisode::query()->whereKey($job->episode_id)->lockForUpdate()->first();
            if (! $this->ownsAttempt($episode, $job)) {
                $job->state = ContentDialogueGeneration::STATE_FAILED;
                $job->error_message = ContentDialogueGeneration::FAILED_MESSAGE;
                $job->finished_at = now();
                $job->save();

                return;
            }

            $input = GenerateContentDialogueData::fromInput($job->input);
            $episode->dialogue()->delete();

            $dialogue = new ContentDialogue;
            $dialogue->id = (string) Str::uuid();
            $dialogue->episode_id = $episode->id;
            $dialogue->save();

            $speakerIds = [];
            foreach ($input->speakers as $index => $profile) {
                $gender = ContentSpeakerProfile::gender($profile['voiceId']);
                $speaker = new ContentSpeaker;
                $speaker->id = (string) Str::uuid();
                $speaker->dialogue_id = $dialogue->id;
                $speaker->name = $profile['name'];
                $speaker->voice_id = $profile['voiceId'];
                $speaker->voice_provider = ContentSpeakerProfile::provider($profile['voiceId']);
                $speaker->proficiency = $profile['proficiency'];
                $speaker->tone = $profile['tone'];
                $speaker->gender = $gender;
                $speaker->color = $profile['color'] ?? ($index === 0 ? '#9333EA' : '#F97316');
                $speaker->avatar_url = ContentSpeakerProfile::avatarUrl($episode->target_language, $gender, $profile['tone']);
                $speaker->save();
                $speakerIds[] = $speaker->id;
            }

            foreach ($result->sentences as $index => $line) {
                $sentence = new ContentSentence;
                $sentence->id = (string) Str::uuid();
                $sentence->dialogue_id = $dialogue->id;
                $sentence->speaker_id = $speakerIds[$index % count($speakerIds)];
                $sentence->sort_order = $index;
                $sentence->text = $line['text'];
                $sentence->translation = $line['translation'];
                $sentence->metadata = $this->metadata($episode->target_language, $line['text'], $line['reading']);
                $sentence->variations = $line['variations'];
                $sentence->selected = false;
                $sentence->save();
            }

            $episode->title = $result->title;
            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->status = 'ready';
            $episode->save();

            $job->state = ContentDialogueGeneration::STATE_COMPLETED;
            $job->progress = 100;
            $job->error_message = null;
            $job->finished_at = now();
            $job->save();
        });
    }

    /** @return array{japanese?: array{kanji: string, kana: string, furigana: string}} */
    private function metadata(string $targetLanguage, string $text, ?string $reading): array
    {
        if ($targetLanguage !== 'ja') {
            return [];
        }

        return ContentJapaneseMetadata::fromText($text, $reading);
    }

    private function ownsAttempt(?ContentEpisode $episode, ContentDialogueGenerationJob $job): bool
    {
        return $episode !== null
            && $episode->status === 'generating'
            && (int) $episode->dialogue_generation_attempt === (int) $job->attempt;
    }
}
