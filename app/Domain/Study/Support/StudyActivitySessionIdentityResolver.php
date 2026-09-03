<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Exceptions\StudyActivityIdentityConflictException;
use App\Domain\Study\Models\StudyActivitySession;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class StudyActivitySessionIdentityResolver
{
    private function __construct() {}

    /**
     * @param  list<StudyActivitySessionData>  $sessions
     * @param  Collection<int, StudyActivitySession>  $existingSessions
     * @return Collection<int, array{session: StudyActivitySessionData, existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}>
     */
    public static function resolve(array $sessions, Collection $existingSessions): Collection
    {
        $identitiesByClientId = $existingSessions
            ->mapWithKeys(fn (StudyActivitySession $session): array => [
                $session->client_session_id => self::identity($session),
            ]);
        $identitiesBySource = $existingSessions
            ->filter(fn (StudyActivitySession $session): bool => $session->source_key !== null)
            ->mapWithKeys(fn (StudyActivitySession $session): array => [
                self::sourceIdentity($session->origin->value, $session->source_key) => self::identity($session),
            ]);

        // Both maps stay shared so each resolved identity is visible to later items in the same batch.
        return collect($sessions)->map(
            fn (StudyActivitySessionData $session): array => self::resolveSession(
                $session,
                $identitiesByClientId,
                $identitiesBySource,
            ),
        );
    }

    /**
     * @param  Collection<string, array{existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}>  $identitiesByClientId
     * @param  Collection<string, array{existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}>  $identitiesBySource
     * @return array{session: StudyActivitySessionData, existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}
     */
    private static function resolveSession(
        StudyActivitySessionData $session,
        Collection $identitiesByClientId,
        Collection $identitiesBySource,
    ): array {
        $clientSessionId = StudyActivitySessionId::normalize($session->clientSessionId);
        $sourceKey = $session->sourceKey?->value;
        self::assertSourceKeyIsSupported($session, $sourceKey);

        $clientMatch = $identitiesByClientId->get($clientSessionId);
        $sourceIdentity = self::nullableSourceIdentity($session->origin->value, $sourceKey);
        $sourceMatch = $sourceIdentity === null ? null : $identitiesBySource->get($sourceIdentity);
        self::assertMatchesReferToSameSession($clientMatch, $sourceMatch);
        $identity = $clientMatch ?? $sourceMatch ?? [
            'existing' => null,
            'client_session_id' => $clientSessionId,
            'origin' => $session->origin->value,
            'source_key' => $sourceKey,
        ];
        self::assertCompatibleSource($identity, $session, $sourceKey);

        $identitiesByClientId->put($clientSessionId, $identity);
        if ($sourceIdentity !== null) {
            $identitiesBySource->put($sourceIdentity, $identity);
        }

        return [
            'session' => $session,
            ...$identity,
        ];
    }

    private static function assertSourceKeyIsSupported(StudyActivitySessionData $session, ?string $sourceKey): void
    {
        if ($sourceKey !== null && ! $session->origin->supportsExternalSourceKey()) {
            throw new InvalidArgumentException(
                'External source keys require their trusted matching provider origin.',
            );
        }
    }

    private static function nullableSourceIdentity(string $origin, ?string $sourceKey): ?string
    {
        return $sourceKey === null ? null : self::sourceIdentity($origin, $sourceKey);
    }

    /**
     * @param  array{existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}|null  $clientMatch
     * @param  array{existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string}|null  $sourceMatch
     */
    private static function assertMatchesReferToSameSession(?array $clientMatch, ?array $sourceMatch): void
    {
        if ($clientMatch === null) {
            return;
        }

        if ($sourceMatch === null) {
            return;
        }

        if ($clientMatch['client_session_id'] === $sourceMatch['client_session_id']) {
            return;
        }

        throw new StudyActivityIdentityConflictException;
    }

    /** @param array{existing: StudyActivitySession|null, client_session_id: string, origin: string, source_key: ?string} $identity */
    private static function assertCompatibleSource(
        array $identity,
        StudyActivitySessionData $session,
        ?string $sourceKey,
    ): void {
        if ($sourceKey === null) {
            return;
        }

        if ($identity['origin'] !== $session->origin->value) {
            throw new StudyActivityIdentityConflictException;
        }

        if ($identity['source_key'] === null) {
            throw new StudyActivityIdentityConflictException;
        }

        if (! hash_equals($identity['source_key'], $sourceKey)) {
            throw new StudyActivityIdentityConflictException;
        }
    }

    private static function sourceIdentity(string $origin, string $sourceKey): string
    {
        return $origin.':'.$sourceKey;
    }

    /** @return array{existing: StudyActivitySession, client_session_id: string, origin: string, source_key: ?string} */
    private static function identity(StudyActivitySession $session): array
    {
        return [
            'existing' => $session,
            'client_session_id' => $session->client_session_id,
            'origin' => $session->origin->value,
            'source_key' => $session->source_key,
        ];
    }
}
