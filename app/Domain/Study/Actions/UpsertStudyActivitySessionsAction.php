<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Exceptions\StudyActivityIdentityConflictException;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySessionId;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            $identitiesByClientId = $existingSessions
                ->mapWithKeys(fn (StudyActivitySession $session): array => [
                    $session->client_session_id => $this->identity($session),
                ]);
            $identitiesBySource = $existingSessions
                ->filter(fn (StudyActivitySession $session): bool => $session->source_key !== null)
                ->mapWithKeys(fn (StudyActivitySession $session): array => [
                    $this->sourceIdentity($session->origin->value, $session->source_key) => $this->identity($session),
                ]);
            $resolved = collect($sessions)
                ->map(function (StudyActivitySessionData $session) use (
                    $identitiesByClientId,
                    $identitiesBySource,
                ): array {
                    $clientSessionId = StudyActivitySessionId::normalize($session->clientSessionId);
                    $sourceKey = $session->sourceKey?->value;
                    if ($sourceKey !== null && ! $session->origin->supportsExternalSourceKey()) {
                        throw new InvalidArgumentException(
                            'External source keys require their trusted matching provider origin.',
                        );
                    }

                    $clientMatch = $identitiesByClientId->get($clientSessionId);
                    $sourceIdentity = $sourceKey === null
                        ? null
                        : $this->sourceIdentity($session->origin->value, $sourceKey);
                    $sourceMatch = $sourceKey === null
                        ? null
                        : $identitiesBySource->get($sourceIdentity);
                    if ($clientMatch !== null && $sourceMatch !== null
                        && $clientMatch['client_session_id'] !== $sourceMatch['client_session_id']) {
                        throw new StudyActivityIdentityConflictException;
                    }

                    $identity = $clientMatch ?? $sourceMatch ?? [
                        'existing' => null,
                        'client_session_id' => $clientSessionId,
                        'origin' => $session->origin->value,
                        'source_key' => $sourceKey,
                    ];
                    if ($sourceKey !== null
                        && ($identity['origin'] !== $session->origin->value
                            || $identity['source_key'] === null
                            || ! hash_equals($identity['source_key'], $sourceKey))) {
                        throw new StudyActivityIdentityConflictException;
                    }
                    $identitiesByClientId->put($clientSessionId, $identity);
                    if ($sourceIdentity !== null) {
                        $identitiesBySource->put($sourceIdentity, $identity);
                    }

                    return [
                        'session' => $session,
                        ...$identity,
                    ];
                });
            $rows = $resolved
                ->reject(fn (array $item): bool => (
                    $item['existing']?->source === StudyActivitySource::Automatic
                ))
                ->map(function (array $item) use ($now, $userId): array {
                    /** @var StudyActivitySessionData $session */
                    $session = $item['session'];
                    /** @var StudyActivitySession|null $existingSession */
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

    private function sourceIdentity(string $origin, string $sourceKey): string
    {
        return $origin.':'.$sourceKey;
    }

    /** @return array{existing: StudyActivitySession, client_session_id: string, origin: string, source_key: ?string} */
    private function identity(StudyActivitySession $session): array
    {
        return [
            'existing' => $session,
            'client_session_id' => $session->client_session_id,
            'origin' => $session->origin->value,
            'source_key' => $session->source_key,
        ];
    }

    private function lockUserOrFail(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
