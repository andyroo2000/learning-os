<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySessionId;
use App\Models\User;
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
            // A first write has no session row to lock, so use the stable owner row
            // to serialize the source decision for every client session ID.
            $this->lockUserOrFail($userId);

            $now = now();
            $clientSessionIds = collect($sessions)->map(
                fn (StudyActivitySessionData $session): string => StudyActivitySessionId::normalize(
                    $session->clientSessionId,
                ),
            );
            $existingSessions = StudyActivitySession::query()
                ->where('user_id', $userId)
                ->whereIn('client_session_id', $clientSessionIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('client_session_id');
            $protectedClientSessionIds = $existingSessions
                ->filter(
                    fn (StudyActivitySession $session): bool => (
                        $session->source === StudyActivitySource::Automatic
                    ),
                )
                ->keys()
                ->all();
            $rows = collect($sessions)
                ->reject(
                    fn (StudyActivitySessionData $session): bool => in_array(
                        StudyActivitySessionId::normalize($session->clientSessionId),
                        $protectedClientSessionIds,
                        true,
                    ),
                )
                ->map(fn (StudyActivitySessionData $session): array => [
                    'id' => (string) Str::ulid(),
                    'user_id' => $userId,
                    'client_session_id' => StudyActivitySessionId::normalize($session->clientSessionId),
                    'category' => $session->category->value,
                    'activity' => $session->activity->value,
                    // A session's origin is immutable once stored. In particular, a
                    // sync client must not upgrade a manual row into an automatic,
                    // permanently protected row by reusing its client session ID.
                    'source' => (
                        $existingSessions->get(
                            StudyActivitySessionId::normalize($session->clientSessionId),
                        )?->source
                            ?? $session->source
                    )->value,
                    // Provenance is part of the event's identity and cannot be
                    // rewritten by a retry using the same client session ID.
                    'origin' => (
                        $existingSessions->get(
                            StudyActivitySessionId::normalize($session->clientSessionId),
                        )?->origin
                            ?? $session->origin
                    )->value,
                    'name' => $session->name,
                    'started_at' => $session->startedAt,
                    'ended_at' => $session->endedAt,
                    'duration_ms' => $session->durationMs,
                    'audio_playback_ms' => $session->audioPlaybackMs,
                    'cards_created' => $session->cardsCreated,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                StudyActivitySession::query()->upsert(
                    $rows,
                    ['user_id', 'client_session_id'],
                    [
                        'category',
                        'activity',
                        'name',
                        'started_at',
                        'ended_at',
                        'duration_ms',
                        'audio_playback_ms',
                        'cards_created',
                        'updated_at',
                    ],
                );
            }

            $byClientId = StudyActivitySession::query()
                ->where('user_id', $userId)
                ->whereIn('client_session_id', $clientSessionIds)
                ->get()
                ->keyBy('client_session_id');

            return collect($sessions)->map(
                fn (StudyActivitySessionData $session): StudyActivitySession => $byClientId[
                    StudyActivitySessionId::normalize($session->clientSessionId)
                ],
            );
        });
    }

    private function lockUserOrFail(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
