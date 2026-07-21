<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CopyConvoLabSampleContentAction
{
    public function handle(AdminUserProjection $account): void
    {
        $userId = filter_var($account->user_id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            throw new \LogicException('Convo Lab sample content requires a canonical user.');
        }
        $account->user_id = $userId;

        ContentSourceLock::acquireConvoLab($account->getConnection());

        $hasSamples = ContentEpisode::query()
            ->where('user_id', $account->user_id)
            ->where('convolab_user_id', $account->convolab_id)
            ->where('is_sample_content', true)
            ->exists()
            || ContentCourse::query()
                ->where('user_id', $account->user_id)
                ->where('convolab_user_id', $account->convolab_id)
                ->where('is_sample_content', true)
                ->exists();

        if ($hasSamples) {
            return;
        }

        $episodeTemplates = $this->episodeTemplates($account);
        [$episodeIds, $sentenceIds, $episodeSignatures] = $this->copyEpisodes($account, $episodeTemplates);
        $this->copyCourses($account, $episodeIds, $sentenceIds, $episodeSignatures);
    }

    /** @return Collection<int, ContentEpisode> */
    private function episodeTemplates(AdminUserProjection $account): Collection
    {
        return ContentEpisode::query()
            ->where('is_sample_content', true)
            ->where('source_system', ContentSourceSystem::CONVOLAB)
            ->where('target_language', $account->preferred_study_language)
            ->where('user_id', '!=', $account->user_id)
            ->where('content_type', 'dialogue')
            ->whereHas('dialogue')
            ->where(function ($query) use ($account): void {
                $query
                    ->where('jlpt_level', $account->proficiency_level)
                    ->orWhereHas('dialogue.speakers', fn ($query) => $query->where('proficiency', $account->proficiency_level));
            })
            ->with([
                'dialogue.speakers' => fn ($query) => $query->orderBy('id'),
                'dialogue.sentences' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->unique(fn (ContentEpisode $episode): string => $this->episodeSignature($episode))
            ->values();
    }

    /**
     * @param  Collection<int, ContentEpisode>  $templates
     * @return array{array<string, string>, array<string, string>, array<string, string>}
     */
    private function copyEpisodes(AdminUserProjection $account, Collection $templates): array
    {
        $episodeIds = [];
        $sentenceIds = [];
        $episodeSignatures = [];

        foreach ($templates as $template) {
            $episode = $template->replicate(['id', 'user_id', 'convolab_user_id', 'source_system', 'created_at', 'updated_at']);
            $episode->id = (string) Str::uuid();
            $episode->user_id = $account->user_id;
            $episode->convolab_user_id = $account->convolab_id;
            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->save();

            $episodeIds[$template->id] = $episode->id;
            $episodeSignatures[$this->episodeSignature($template)] = $episode->id;

            $templateDialogue = $template->dialogue;
            if (! $templateDialogue instanceof ContentDialogue) {
                continue;
            }

            $dialogue = $templateDialogue->replicate(['id', 'episode_id', 'created_at', 'updated_at']);
            $dialogue->id = (string) Str::uuid();
            $dialogue->episode_id = $episode->id;
            $dialogue->save();

            $speakerIds = [];
            foreach ($templateDialogue->speakers as $templateSpeaker) {
                $speaker = $templateSpeaker->replicate(['id', 'dialogue_id']);
                $speaker->id = (string) Str::uuid();
                $speaker->dialogue_id = $dialogue->id;
                $speaker->save();
                $speakerIds[$templateSpeaker->id] = $speaker->id;
            }

            foreach ($templateDialogue->sentences as $templateSentence) {
                $speakerId = $speakerIds[$templateSentence->speaker_id] ?? null;
                if (! is_string($speakerId)) {
                    throw new \LogicException('Sample sentence references a missing speaker.');
                }

                $sentence = $templateSentence->replicate(['id', 'dialogue_id', 'speaker_id', 'created_at', 'updated_at']);
                $sentence->id = (string) Str::uuid();
                $sentence->dialogue_id = $dialogue->id;
                $sentence->speaker_id = $speakerId;
                $sentence->save();
                $sentenceIds[$templateSentence->id] = $sentence->id;
            }
        }

        return [$episodeIds, $sentenceIds, $episodeSignatures];
    }

    /**
     * @param  array<string, string>  $episodeIds
     * @param  array<string, string>  $sentenceIds
     * @param  array<string, string>  $episodeSignatures
     */
    private function copyCourses(
        AdminUserProjection $account,
        array $episodeIds,
        array $sentenceIds,
        array $episodeSignatures,
    ): void {
        $templates = ContentCourse::query()
            ->where('is_sample_content', true)
            ->where('source_system', ContentSourceSystem::CONVOLAB)
            ->where('target_language', $account->preferred_study_language)
            ->where('jlpt_level', $account->proficiency_level)
            ->where('user_id', '!=', $account->user_id)
            ->with([
                'coreItems' => fn ($query) => $query->orderBy('id'),
                'courseEpisodes.episode',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->unique(fn (ContentCourse $course): string => implode("\0", [
                $course->title,
                $course->target_language,
                (string) $course->jlpt_level,
            ]));

        foreach ($templates as $template) {
            $linkedEpisodes = [];
            foreach ($template->courseEpisodes as $templateLink) {
                $mappedId = $episodeIds[$templateLink->episode_id]
                    ?? ($templateLink->episode instanceof ContentEpisode
                        ? ($episodeSignatures[$this->episodeSignature($templateLink->episode)] ?? null)
                        : null);
                if (! is_string($mappedId)) {
                    $linkedEpisodes = [];
                    break;
                }
                $linkedEpisodes[] = [$templateLink, $mappedId];
            }

            if ($template->courseEpisodes->isNotEmpty() && $linkedEpisodes === []) {
                continue;
            }

            $course = $template->replicate(['id', 'user_id', 'convolab_user_id', 'source_system', 'created_at', 'updated_at']);
            $course->id = (string) Str::uuid();
            $course->user_id = $account->user_id;
            $course->convolab_user_id = $account->convolab_id;
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->save();

            foreach ($linkedEpisodes as [$templateLink, $episodeId]) {
                $link = $templateLink->replicate(['id', 'episode_id', 'convolab_course_id', 'source_system']);
                $link->id = (string) Str::uuid();
                $link->episode_id = $episodeId;
                $link->convolab_course_id = $course->id;
                $link->source_system = ContentSourceSystem::LEARNING_OS;
                $link->save();
            }

            foreach ($template->coreItems as $templateItem) {
                $item = $templateItem->replicate(['id', 'course_id', 'source_episode_id', 'source_sentence_id']);
                $item->id = (string) Str::uuid();
                $item->course_id = $course->id;
                // Provenance pointers must never retain another user's source IDs.
                $item->source_episode_id = $templateItem->source_episode_id === null
                    ? null
                    : ($episodeIds[$templateItem->source_episode_id] ?? null);
                $item->source_sentence_id = $templateItem->source_sentence_id === null
                    ? null
                    : ($sentenceIds[$templateItem->source_sentence_id] ?? null);
                $item->save();
            }
        }
    }

    private function episodeSignature(ContentEpisode $episode): string
    {
        return implode("\0", [
            $episode->title,
            $episode->source_text,
            $episode->target_language,
            $episode->native_language,
            (string) $episode->jlpt_level,
        ]);
    }
}
