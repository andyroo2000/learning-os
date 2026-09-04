<?php

namespace Tests\Unit\Japanese;

use App\Domain\Japanese\Exceptions\WaniKaniApiException;
use App\Domain\Japanese\Services\WaniKaniApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WaniKaniApiClientTest extends TestCase
{
    public function test_vocabulary_subject_validation_fails_before_requesting_the_next_batch(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/assignments')) {
                return Http::response($this->assignments(501));
            }

            return Http::response([
                'pages' => ['next_url' => null],
                'data' => [['id' => 1, 'object' => 'vocabulary', 'data' => ['characters' => '']]],
            ]);
        });

        try {
            resolve(WaniKaniApiClient::class)->vocabularyProgress('test-token', null);
            $this->fail('Malformed vocabulary subjects must fail the sync.');
        } catch (WaniKaniApiException $exception) {
            $this->assertSame(502, $exception->getCode());
        }

        $subjectRequests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request): bool => str_contains($request->url(), '/subjects'));

        $this->assertCount(1, $subjectRequests);
    }

    /** @return array{pages: array{next_url: null}, data: list<array<string, mixed>>} */
    private function assignments(int $count): array
    {
        return [
            'pages' => ['next_url' => null],
            'data' => array_map(
                fn (int $subjectId): array => [
                    'data_updated_at' => null,
                    'data' => [
                        'subject_id' => $subjectId,
                        'subject_type' => 'vocabulary',
                        'srs_stage' => 1,
                        'passed_at' => null,
                        'burned_at' => null,
                        'hidden' => false,
                    ],
                ],
                range(1, $count),
            ),
        ];
    }
}
