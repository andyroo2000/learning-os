<?php

namespace App\Domain\Japanese\Services;

use App\Domain\Japanese\Data\WaniKaniPassedKanji;
use App\Domain\Japanese\Data\WaniKaniVocabularyProgress;
use App\Domain\Japanese\Exceptions\WaniKaniApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class WaniKaniApiClient
{
    private const REVISION = '20170710';

    private const SUBJECT_BATCH_SIZE = 500;

    private const MAX_PAGES = 100;

    public function validateToken(string $apiToken): void
    {
        $this->request($apiToken, '/user');
    }

    public function immediateReviewCount(string $apiToken): int
    {
        $response = $this->requestUrl(
            $apiToken,
            $this->baseUrl().'/assignments',
            ['immediately_available_for_review' => 'true'],
        );
        $payload = $response->json();
        $count = is_array($payload) ? ($payload['total_count'] ?? null) : null;

        if (! is_int($count) || $count < 0) {
            throw WaniKaniApiException::invalidResponse();
        }

        return $count;
    }

    /** @return list<WaniKaniPassedKanji> */
    public function passedKanji(string $apiToken, ?CarbonImmutable $updatedAfter): array
    {
        $assignments = $this->collection(
            $apiToken,
            '/assignments',
            $this->assignmentQuery('kanji', $updatedAfter),
        );
        $passedBySubjectId = $this->passedKanjiAssignments($assignments);

        if ($passedBySubjectId === []) {
            return [];
        }

        $charactersBySubjectId = $this->kanjiCharacters(
            $apiToken,
            array_keys($passedBySubjectId),
        );

        return $this->makePassedKanji($passedBySubjectId, $charactersBySubjectId);
    }

    /** @return list<WaniKaniVocabularyProgress> */
    public function vocabularyProgress(string $apiToken, ?CarbonImmutable $updatedAfter): array
    {
        $assignments = $this->collection(
            $apiToken,
            '/assignments',
            $this->assignmentQuery('vocabulary,kana_vocabulary', $updatedAfter),
        );
        $assignmentsBySubjectId = $this->vocabularyAssignments($assignments);

        if ($assignmentsBySubjectId === []) {
            return [];
        }

        $subjectsById = $this->vocabularySubjects(
            $apiToken,
            array_keys($assignmentsBySubjectId),
        );

        return $this->makeVocabularyProgress($assignmentsBySubjectId, $subjectsById);
    }

    /** @return array<string, string> */
    private function assignmentQuery(string $subjectTypes, ?CarbonImmutable $updatedAfter): array
    {
        $query = ['subject_types' => $subjectTypes];
        if ($updatedAfter !== null) {
            $query['updated_after'] = $updatedAfter->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return array<int, CarbonImmutable>
     */
    private function passedKanjiAssignments(array $assignments): array
    {
        $passedBySubjectId = [];
        foreach ($assignments as $assignment) {
            $passedAssignment = $this->passedKanjiAssignment($assignment);
            if ($passedAssignment !== null) {
                $passedBySubjectId[$passedAssignment['subjectId']] = $passedAssignment['passedAt'];
            }
        }

        return $passedBySubjectId;
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array{subjectId: int, passedAt: CarbonImmutable}|null
     */
    private function passedKanjiAssignment(array $assignment): ?array
    {
        $data = $this->resourceData($assignment);
        $subjectId = $data['subject_id'] ?? null;
        $subjectType = $data['subject_type'] ?? null;
        $passedAt = $data['passed_at'] ?? null;

        if (! $this->isPassedKanjiAssignment($subjectId, $subjectType, $passedAt)) {
            return null;
        }

        return ['subjectId' => $subjectId, 'passedAt' => $this->timestamp($passedAt)];
    }

    private function isPassedKanjiAssignment(mixed $subjectId, mixed $subjectType, mixed $passedAt): bool
    {
        return is_int($subjectId) && $subjectType === 'kanji' && is_string($passedAt);
    }

    /**
     * @param  list<int>  $subjectIds
     * @return array<int, string>
     */
    private function kanjiCharacters(string $apiToken, array $subjectIds): array
    {
        $charactersBySubjectId = [];
        foreach (array_chunk($subjectIds, self::SUBJECT_BATCH_SIZE) as $batch) {
            foreach ($this->subjects($apiToken, $batch) as $subject) {
                $parsedSubject = $this->kanjiSubject($subject);
                if ($parsedSubject !== null) {
                    $charactersBySubjectId[$parsedSubject['id']] = $parsedSubject['characters'];
                }
            }
        }

        return $charactersBySubjectId;
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array{id: int, characters: string}|null
     */
    private function kanjiSubject(array $subject): ?array
    {
        $id = $subject['id'] ?? null;
        $object = $subject['object'] ?? null;
        $characters = $this->resourceData($subject)['characters'] ?? null;

        if (! $this->isKanjiSubject($id, $object, $characters)) {
            return null;
        }

        return ['id' => $id, 'characters' => $characters];
    }

    private function isKanjiSubject(mixed $id, mixed $object, mixed $characters): bool
    {
        return is_int($id) && $object === 'kanji' && is_string($characters) && $characters !== '';
    }

    /**
     * @param  array<int, CarbonImmutable>  $passedBySubjectId
     * @param  array<int, string>  $charactersBySubjectId
     * @return list<WaniKaniPassedKanji>
     */
    private function makePassedKanji(array $passedBySubjectId, array $charactersBySubjectId): array
    {
        $result = [];
        foreach ($passedBySubjectId as $subjectId => $passedAt) {
            $character = $charactersBySubjectId[$subjectId] ?? null;
            if (! is_string($character)) {
                throw WaniKaniApiException::invalidResponse();
            }
            $result[] = new WaniKaniPassedKanji($subjectId, $character, $passedAt);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return array<int, array{subjectType: string, srsStage: int, passedAt: ?CarbonImmutable, burnedAt: ?CarbonImmutable, hidden: bool, updatedAt: ?CarbonImmutable}>
     */
    private function vocabularyAssignments(array $assignments): array
    {
        $assignmentsBySubjectId = [];
        foreach ($assignments as $assignment) {
            $parsed = $this->vocabularyAssignment($assignment);
            $assignmentsBySubjectId[$parsed['subjectId']] = $parsed['progress'];
        }

        return $assignmentsBySubjectId;
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array{subjectId: int, progress: array{subjectType: string, srsStage: int, passedAt: ?CarbonImmutable, burnedAt: ?CarbonImmutable, hidden: bool, updatedAt: ?CarbonImmutable}}
     */
    private function vocabularyAssignment(array $assignment): array
    {
        $data = $this->resourceData($assignment);
        $subjectId = $data['subject_id'] ?? null;
        $subjectType = $data['subject_type'] ?? null;
        $srsStage = $data['srs_stage'] ?? null;
        $hidden = $data['hidden'] ?? false;

        // Vocabulary sync deliberately fails closed. Skipping a malformed row while
        // advancing the incremental cursor could permanently undercount mastery.
        if (! $this->isVocabularyAssignment($subjectId, $subjectType, $srsStage, $hidden)) {
            throw WaniKaniApiException::invalidResponse();
        }

        return [
            'subjectId' => $subjectId,
            'progress' => [
                'subjectType' => $subjectType,
                'srsStage' => $srsStage,
                'passedAt' => $this->optionalTimestamp($data['passed_at'] ?? null),
                'burnedAt' => $this->optionalTimestamp($data['burned_at'] ?? null),
                'hidden' => $hidden,
                'updatedAt' => $this->optionalTimestamp($assignment['data_updated_at'] ?? null),
            ],
        ];
    }

    private function isVocabularyAssignment(
        mixed $subjectId,
        mixed $subjectType,
        mixed $srsStage,
        mixed $hidden,
    ): bool {
        return is_int($subjectId)
            && in_array($subjectType, ['vocabulary', 'kana_vocabulary'], true)
            && is_int($srsStage)
            && $srsStage >= 0
            && $srsStage <= 9
            && is_bool($hidden);
    }

    /**
     * @param  list<int>  $subjectIds
     * @return array<int, array{subjectType: string, characters: string, readings: list<string>, meanings: list<string>, hiddenAt: ?CarbonImmutable, updatedAt: ?CarbonImmutable}>
     */
    private function vocabularySubjects(string $apiToken, array $subjectIds): array
    {
        $subjectsById = [];
        foreach (array_chunk($subjectIds, self::SUBJECT_BATCH_SIZE) as $batch) {
            foreach ($this->subjects($apiToken, $batch) as $subject) {
                $parsed = $this->vocabularySubject($subject);
                $subjectsById[$parsed['id']] = $parsed['details'];
            }
        }

        return $subjectsById;
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return array{id: int, details: array{subjectType: string, characters: string, readings: list<string>, meanings: list<string>, hiddenAt: ?CarbonImmutable, updatedAt: ?CarbonImmutable}}
     */
    private function vocabularySubject(array $subject): array
    {
        $id = $subject['id'] ?? null;
        $object = $subject['object'] ?? null;
        $data = $this->resourceData($subject);
        $characters = $data['characters'] ?? null;

        if (! $this->isVocabularySubject($id, $object, $characters)) {
            throw WaniKaniApiException::invalidResponse();
        }

        $readings = $object === 'kana_vocabulary'
            ? [$characters]
            : $this->acceptedValues($data['readings'] ?? null, 'reading');
        $meanings = $this->acceptedValues($data['meanings'] ?? null, 'meaning');
        if ($readings === [] || $meanings === []) {
            throw WaniKaniApiException::invalidResponse();
        }

        return [
            'id' => $id,
            'details' => [
                'subjectType' => $object,
                'characters' => $characters,
                'readings' => $readings,
                'meanings' => $meanings,
                'hiddenAt' => $this->optionalTimestamp($data['hidden_at'] ?? null),
                'updatedAt' => $this->optionalTimestamp($subject['data_updated_at'] ?? null),
            ],
        ];
    }

    private function isVocabularySubject(mixed $id, mixed $object, mixed $characters): bool
    {
        return is_int($id)
            && in_array($object, ['vocabulary', 'kana_vocabulary'], true)
            && is_string($characters)
            && $characters !== '';
    }

    /** @param list<int> $subjectIds
     * @return list<array<string, mixed>>
     */
    private function subjects(string $apiToken, array $subjectIds): array
    {
        return $this->collection($apiToken, '/subjects', ['ids' => implode(',', $subjectIds)]);
    }

    /**
     * @param  array<int, array{subjectType: string, srsStage: int, passedAt: ?CarbonImmutable, burnedAt: ?CarbonImmutable, hidden: bool, updatedAt: ?CarbonImmutable}>  $assignmentsBySubjectId
     * @param  array<int, array{subjectType: string, characters: string, readings: list<string>, meanings: list<string>, hiddenAt: ?CarbonImmutable, updatedAt: ?CarbonImmutable}>  $subjectsById
     * @return list<WaniKaniVocabularyProgress>
     */
    private function makeVocabularyProgress(array $assignmentsBySubjectId, array $subjectsById): array
    {
        $result = [];
        foreach ($assignmentsBySubjectId as $subjectId => $assignment) {
            $subject = $subjectsById[$subjectId] ?? null;
            if ($subject === null || $subject['subjectType'] !== $assignment['subjectType']) {
                throw WaniKaniApiException::invalidResponse();
            }
            $result[] = $this->vocabularyProgressItem($subjectId, $assignment, $subject);
        }

        return $result;
    }

    /**
     * @param  array{subjectType: string, srsStage: int, passedAt: ?CarbonImmutable, burnedAt: ?CarbonImmutable, hidden: bool, updatedAt: ?CarbonImmutable}  $assignment
     * @param  array{subjectType: string, characters: string, readings: list<string>, meanings: list<string>, hiddenAt: ?CarbonImmutable, updatedAt: ?CarbonImmutable}  $subject
     */
    private function vocabularyProgressItem(int $subjectId, array $assignment, array $subject): WaniKaniVocabularyProgress
    {
        return new WaniKaniVocabularyProgress(
            subjectId: $subjectId,
            subjectType: $subject['subjectType'],
            characters: $subject['characters'],
            readings: $subject['readings'],
            meanings: $subject['meanings'],
            srsStage: $assignment['srsStage'],
            passedAt: $assignment['passedAt'],
            burnedAt: $assignment['burnedAt'],
            hidden: $assignment['hidden'],
            assignmentUpdatedAt: $assignment['updatedAt'],
            subjectUpdatedAt: $subject['updatedAt'],
            hiddenAt: $subject['hiddenAt'],
        );
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function collection(string $apiToken, string $path, array $query): array
    {
        $items = [];
        $url = $this->baseUrl().$path;
        $visitedUrls = [];

        do {
            $this->assertUnvisitedUrl($url, $visitedUrls);
            $visitedUrls[$url] = true;
            $payload = $this->collectionPayload($this->requestUrl($apiToken, $url, $query));
            array_push($items, ...$this->collectionResources($payload['data']));
            $url = $this->nextCollectionUrl($payload);
            $query = [];
        } while ($url !== null);

        return $items;
    }

    /** @param array<string, true> $visitedUrls */
    private function assertUnvisitedUrl(string $url, array $visitedUrls): void
    {
        if (isset($visitedUrls[$url]) || count($visitedUrls) >= self::MAX_PAGES) {
            throw WaniKaniApiException::invalidResponse();
        }
    }

    /** @return array<string, mixed> */
    private function collectionPayload(Response $response): array
    {
        $payload = $response->json();
        if (! $this->isCollectionPayload($payload)) {
            throw WaniKaniApiException::invalidResponse();
        }

        return $payload;
    }

    private function isCollectionPayload(mixed $payload): bool
    {
        return is_array($payload) && isset($payload['data']) && is_array($payload['data']);
    }

    /**
     * @param  array<mixed>  $resources
     * @return list<array<string, mixed>>
     */
    private function collectionResources(array $resources): array
    {
        $items = [];
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                throw WaniKaniApiException::invalidResponse();
            }
            $items[] = $resource;
        }

        return $items;
    }

    /** @param array<string, mixed> $payload */
    private function nextCollectionUrl(array $payload): ?string
    {
        $nextUrl = $payload['pages']['next_url'] ?? null;
        if (! $this->isAllowedNextUrl($nextUrl)) {
            throw WaniKaniApiException::invalidResponse();
        }

        return $nextUrl;
    }

    private function isAllowedNextUrl(mixed $nextUrl): bool
    {
        return $nextUrl === null || (is_string($nextUrl) && $this->isAllowedUrl($nextUrl));
    }

    private function request(string $apiToken, string $path): Response
    {
        return $this->requestUrl($apiToken, $this->baseUrl().$path, []);
    }

    /** @param array<string, string> $query */
    private function requestUrl(string $apiToken, string $url, array $query): Response
    {
        try {
            $request = Http::acceptJson()
                ->withToken($apiToken)
                ->withHeaders(['Wanikani-Revision' => self::REVISION])
                ->timeout(10)
                ->retry(2, 150, throw: false);
            // WaniKani's pagination URL already contains the active filters and cursor.
            // Passing an empty query array to Laravel replaces that query string.
            $response = $query === []
                ? $request->get($url)
                : $request->get($url, $query);
        } catch (ConnectionException) {
            throw WaniKaniApiException::unavailable();
        }

        $this->assertSuccessfulResponse($response);

        return $response;
    }

    private function assertSuccessfulResponse(Response $response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw WaniKaniApiException::invalidToken();
        }
        if ($response->failed()) {
            throw WaniKaniApiException::unavailable();
        }
    }

    /** @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function resourceData(array $resource): array
    {
        $data = $resource['data'] ?? null;
        if (! is_array($data)) {
            throw WaniKaniApiException::invalidResponse();
        }

        return $data;
    }

    private function optionalTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw WaniKaniApiException::invalidResponse();
        }

        return $this->timestamp($value);
    }

    private function timestamp(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw WaniKaniApiException::invalidResponse();
        }
    }

    /** @return list<string> */
    private function acceptedValues(mixed $items, string $valueKey): array
    {
        if (! is_array($items)) {
            throw WaniKaniApiException::invalidResponse();
        }

        $values = [];
        foreach ($items as $item) {
            $value = $this->acceptedValue($item, $valueKey);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function acceptedValue(mixed $item, string $valueKey): ?string
    {
        if (! is_array($item)) {
            throw WaniKaniApiException::invalidResponse();
        }

        $value = $item[$valueKey] ?? null;
        $accepted = $item['accepted_answer'] ?? true;
        if (! $this->isAcceptedValue($value, $accepted)) {
            throw WaniKaniApiException::invalidResponse();
        }

        return $accepted ? $value : null;
    }

    private function isAcceptedValue(mixed $value, mixed $accepted): bool
    {
        return is_string($value) && $value !== '' && is_bool($accepted);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.wanikani.base_url', 'https://api.wanikani.com/v2'), '/');
    }

    private function isAllowedUrl(string $url): bool
    {
        $base = parse_url($this->baseUrl());
        $candidate = parse_url($url);

        return is_array($base)
            && is_array($candidate)
            && ($candidate['scheme'] ?? null) === ($base['scheme'] ?? null)
            && ($candidate['host'] ?? null) === ($base['host'] ?? null)
            && ($candidate['port'] ?? null) === ($base['port'] ?? null);
    }
}
