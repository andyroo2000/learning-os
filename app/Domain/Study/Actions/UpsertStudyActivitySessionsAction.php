<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Models\StudyActivitySession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpsertStudyActivitySessionsAction
{
    /**
     * @param  list<StudyActivitySessionData>  $sessions
     * @return Collection<int, StudyActivitySession>
     */
    public function handle(int $userId, array $sessions): Collection
    {
        return DB::transaction(function () use ($userId, $sessions): Collection {
            $now = now();
            $rows = collect($sessions)->map(fn (StudyActivitySessionData $session): array => [
                'id' => (string) Str::ulid(),
                'user_id' => $userId,
                'client_session_id' => $session->clientSessionId,
                'category' => $session->category->value,
                'activity' => $session->activity->value,
                'source' => $session->source->value,
                'name' => $session->name,
                'started_at' => $session->startedAt,
                'ended_at' => $session->endedAt,
                'duration_ms' => $session->durationMs,
                'audio_playback_ms' => $session->audioPlaybackMs,
                'cards_created' => $session->cardsCreated,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            StudyActivitySession::query()->upsert(
                $rows,
                ['user_id', 'client_session_id'],
                [
                    'category',
                    'activity',
                    'source',
                    'name',
                    'started_at',
                    'ended_at',
                    'duration_ms',
                    'audio_playback_ms',
                    'cards_created',
                    'updated_at',
                ],
            );

            $byClientId = StudyActivitySession::query()
                ->where('user_id', $userId)
                ->whereIn('client_session_id', collect($sessions)->pluck('clientSessionId'))
                ->get()
                ->keyBy('client_session_id');

            return collect($sessions)->map(
                fn (StudyActivitySessionData $session): StudyActivitySession => $byClientId[$session->clientSessionId],
            );
        });
    }
}
