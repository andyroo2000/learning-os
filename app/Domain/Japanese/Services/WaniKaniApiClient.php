<?php

namespace App\Domain\Japanese\Services;

use App\Domain\Japanese\Data\WaniKaniPassedKanji;
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

    /** @return list<WaniKaniPassedKanji> */
    public function passedKanji(string $apiToken, ?CarbonImmutable $updatedAfter): array
    {
        $query = ['subject_types' => 'kanji'];
        if ($updatedAfter !== null) {
            $query['updated_after'] = $updatedAfter->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        $assignments = $this->collection($apiToken, '/assignments', $query);
        $passedBySubjectId = [];

        foreach ($assignments as $assignment) {
            $data = $this->resourceData($assignment);
            $subjectId = $data['subject_id'] ?? null;
            $subjectType = $data['subject_type'] ?? null;
            $passedAt = $data['passed_at'] ?? null;

            if (! is_int($subjectId) || $subjectType !== 'kanji' || ! is_string($passedAt)) {
                continue;
            }

            try {
                $passedBySubjectId[$subjectId] = CarbonImmutable::parse($passedAt)->utc();
            } catch (\Throwable) {
                throw WaniKaniApiException::invalidResponse();
            }
        }

        if ($passedBySubjectId === []) {
            return [];
        }

        $charactersBySubjectId = [];
        foreach (array_chunk(array_keys($passedBySubjectId), self::SUBJECT_BATCH_SIZE) as $subjectIds) {
            foreach ($this->collection($apiToken, '/subjects', ['ids' => implode(',', $subjectIds)]) as $subject) {
                $id = $subject['id'] ?? null;
                $object = $subject['object'] ?? null;
                $data = $this->resourceData($subject);
                $characters = $data['characters'] ?? null;

                if (is_int($id) && $object === 'kanji' && is_string($characters) && $characters !== '') {
                    $charactersBySubjectId[$id] = $characters;
                }
            }
        }

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
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function collection(string $apiToken, string $path, array $query): array
    {
        $items = [];
        $url = $this->baseUrl().$path;
        $visitedUrls = [];

        do {
            if (isset($visitedUrls[$url]) || count($visitedUrls) >= self::MAX_PAGES) {
                throw WaniKaniApiException::invalidResponse();
            }
            $visitedUrls[$url] = true;
            $response = $this->requestUrl($apiToken, $url, $query);
            $payload = $response->json();
            if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data'])) {
                throw WaniKaniApiException::invalidResponse();
            }

            foreach ($payload['data'] as $resource) {
                if (! is_array($resource)) {
                    throw WaniKaniApiException::invalidResponse();
                }
                $items[] = $resource;
            }

            $nextUrl = $payload['pages']['next_url'] ?? null;
            if ($nextUrl !== null && (! is_string($nextUrl) || ! $this->isAllowedUrl($nextUrl))) {
                throw WaniKaniApiException::invalidResponse();
            }

            $url = $nextUrl;
            $query = [];
        } while ($url !== null);

        return $items;
    }

    private function request(string $apiToken, string $path): Response
    {
        return $this->requestUrl($apiToken, $this->baseUrl().$path, []);
    }

    /** @param array<string, string> $query */
    private function requestUrl(string $apiToken, string $url, array $query): Response
    {
        try {
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->withHeaders(['Wanikani-Revision' => self::REVISION])
                ->timeout(10)
                ->retry(2, 150, throw: false)
                ->get($url, $query);
        } catch (ConnectionException) {
            throw WaniKaniApiException::unavailable();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw WaniKaniApiException::invalidToken();
        }
        if ($response->failed()) {
            throw WaniKaniApiException::unavailable();
        }

        return $response;
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
