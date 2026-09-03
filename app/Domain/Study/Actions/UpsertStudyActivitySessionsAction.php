<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySessionId;
use App\Domain\Study\Support\StudyActivitySessionIdentityResolver;
use App\Models\User;
use Carbon\CarbonInterface;
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
            $sourceKeys = collect($sessions)
                ->map(fn (StudyActivitySessionData $session): ?string => $session->sourceKey?->value)
                ->filter()
                ->values();
            $existingSessions = StudyActivitySession::query()
                ->where('user_id', $userId)
                ->where(function ($query) use ($clientSessionIds, $sourceKeys): void {
                    $query->whereIn('client_session_id', $clientSessionIds);
                    if ($sourceKeys->isNotEmpty()) {
                        $query->orWhereIn('source_key', $sourceKeys);
                    }
                })
                ->lockForUpdate()
                ->get(['id', 'client_session_id', 'source', 'origin', 'source_key']);
            $resolved = StudyActivitySessionIdentityResolver::resolve($sessions, $existingSessions);
            $this->upsertResolvedSessions($resolved, $userId, $now);

            $resolvedClientSessionIds = $resolved->pluck('client_session_id');
            $byClientId = StudyActivitySession::query()
                ->where('user_id', $userId)
                ->whereIn('client_session_id', $resolvedClientSessionIds)
                ->get()
                ->keyBy('client_session_id');

            return $resolved->map(
                fn (array $item): StudyActivitySession => $byClientId[$item['client_session_id']],
            );
        });
    }

    /**
     * @param  Collection<int, array{session: StudyActivitySessionData, existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}>  $resolved
     */
    private function upsertResolvedSessions(Collection $resolved, int $userId, CarbonInterface $now): void
    {
        $rows = $resolved
            ->reject(fn (array $item): bool => (
                $item['existing']?->source === StudyActivitySource::Automatic
                || ($item['existing'] !== null
                    && $item['session']->source === StudyActivitySource::Calendar
                    && $item['existing']->source !== StudyActivitySource::Calendar)
            ))
            ->map(function (array $item) use ($now, $userId): array {
                $session = $item['session'];
                $existingSession = $item['existing'];

                return [
                    'id' => (string) Str::ulid(),
                    'user_id' => $userId,
                    'client_session_id' => $item['client_session_id'],
                    'category' => $session->category->value,
                    'activity' => $session->activity->value,
                    // A session's capture source is immutable once stored. In particular, a
                    // sync client must not upgrade a manual row into an automatic,
                    // permanently protected row by reusing its client session ID.
                    'source' => ($existingSession?->source ?? $session->source)->value,
                    // Provenance is part of the event's identity and cannot be
                    // rewritten by a retry using the same client session ID.
                    'origin' => ($existingSession?->origin ?? $session->origin)->value,
                    'source_key' => $item['source_key'],
                    'name' => $session->name,
                    'started_at' => $session->startedAt,
                    'ended_at' => $session->endedAt,
                    'duration_ms' => $session->durationMs,
                    'audio_playback_ms' => $session->audioPlaybackMs,
                    'cards_created' => $session->cardsCreated,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->keyBy('client_session_id')
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

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

    private function lockUserOrFail(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
