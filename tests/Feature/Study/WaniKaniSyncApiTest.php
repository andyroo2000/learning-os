<?php

namespace Tests\Feature\Study;

use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Study\BuildsWaniKaniApiResponses;
use Tests\TestCase;

class WaniKaniSyncApiTest extends TestCase
{
    use BuildsWaniKaniApiResponses;
    use RefreshDatabase;

    public function test_sync_persists_ever_passed_kanji_and_vocabulary_with_separate_incremental_cursors(): void
    {
        $user = $this->signIn();
        $this->fakeIncrementalSyncResponses();

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson([
                'added' => 1,
                'effectiveTotal' => 1,
                'version' => 1,
                'reviewCount' => 32,
                'vocabularyAdded' => 3,
                'vocabularyKnownTotal' => 3,
                'vocabularyMatchedTotal' => 2,
            ]);

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson([
                'added' => 0,
                'effectiveTotal' => 1,
                'version' => 1,
                'reviewCount' => 9,
                'vocabularyAdded' => 0,
                'vocabularyKnownTotal' => 3,
                'vocabularyMatchedTotal' => 2,
            ]);

        $this->assertIncrementalSyncState($user->id);
        $this->assertIncrementalSyncRequests();
        $this->assertReviewCountResponse($user->id);
    }

    public function test_review_count_failure_preserves_successful_kanji_and_vocabulary_sync(): void
    {
        Log::spy();
        $user = $this->signIn();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/user')) {
                return Http::response(['object' => 'user']);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/assignments')) {
                if (($query['immediately_available_for_review'] ?? null) === 'true') {
                    return Http::response(['data' => []]);
                }

                if (($query['subject_types'] ?? null) === 'kanji') {
                    return Http::response($this->assignmentCollection([
                        $this->assignment(440, 'kanji', 5, '2026-07-15T12:00:00.000000Z'),
                    ]));
                }

                return Http::response($this->assignmentCollection([]));
            }

            return Http::response($this->subjectCollection([$this->kanjiSubject(440, '一')]));
        });

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();
        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $previousCountUpdatedAt = now()->subHour();
        $connection->review_count = 41;
        $connection->review_count_updated_at = $previousCountUpdatedAt;
        $connection->save();
        $previousCountUpdatedAt = $connection->fresh()->review_count_updated_at;

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson([
                'added' => 1,
                'effectiveTotal' => 1,
                'version' => 1,
                'reviewCount' => 41,
                'vocabularyAdded' => 0,
                'vocabularyKnownTotal' => 0,
                'vocabularyMatchedTotal' => 0,
            ]);

        $this->assertDatabaseHas('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '一',
            'wanikani_subject_id' => 440,
        ]);
        $connection->refresh();
        $this->assertNotNull($connection->last_synced_at);
        $this->assertNotNull($connection->vocabulary_assignments_synced_through_at);
        $this->assertSame(41, $connection->review_count);
        $this->assertTrue($connection->review_count_updated_at->equalTo($previousCountUpdatedAt));
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('WaniKani review count refresh failed; preserving the cached count.', [
                'user_id' => $user->id,
                'status' => 502,
            ]);
    }

    public function test_concurrent_sync_for_the_same_user_returns_conflict(): void
    {
        $user = $this->signIn();
        $lock = Cache::lock("wanikani-sync:user:{$user->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/api/study/wanikani/sync')
                ->assertConflict()
                ->assertExactJson(['message' => 'A WaniKani sync is already in progress.']);
        } finally {
            $lock->release();
        }
    }

    private function fakeIncrementalSyncResponses(): void
    {
        $kanjiAssignmentCalls = 0;
        $vocabularyAssignmentCalls = 0;
        $reviewCountCalls = 0;
        Http::fake(function ($request) use (&$kanjiAssignmentCalls, &$vocabularyAssignmentCalls, &$reviewCountCalls) {
            $url = $request->url();
            if (str_ends_with($url, '/user')) {
                return Http::response(['object' => 'user']);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/assignments')) {
                if (($query['immediately_available_for_review'] ?? null) === 'true') {
                    $reviewCountCalls++;

                    return Http::response($this->assignmentCollection(
                        [],
                        totalCount: $reviewCountCalls === 1 ? 32 : 9,
                    ));
                }

                if (($query['subject_types'] ?? null) === 'kanji') {
                    $kanjiAssignmentCalls++;

                    return Http::response($this->assignmentCollection($kanjiAssignmentCalls === 1 ? [
                        $this->assignment(440, 'kanji', 5, '2026-07-15T12:00:00.000000Z'),
                        $this->assignment(441, 'kanji', 1, null),
                    ] : []));
                }

                $vocabularyAssignmentCalls++;

                return Http::response($this->assignmentCollection($vocabularyAssignmentCalls === 1 ? [
                    $this->assignment(500, 'vocabulary', 5, '2026-07-16T12:00:00.000000Z'),
                    $this->assignment(501, 'vocabulary', 1, null),
                    $this->assignment(502, 'kana_vocabulary', 7, '2026-07-17T12:00:00.000000Z'),
                    $this->assignment(503, 'vocabulary', 6, '2026-07-18T12:00:00.000000Z'),
                ] : [
                    // Coverage is intentionally cumulative: falling below Guru later
                    // does not erase evidence that the subject was previously passed.
                    $this->assignment(500, 'vocabulary', 2, null),
                ]));
            }

            if (($query['ids'] ?? null) === '440') {
                return Http::response($this->subjectCollection([$this->kanjiSubject(440, '一')]));
            }

            return Http::response($this->subjectCollection([
                $this->vocabularySubject(500, '赤', ['あか'], ['Red']),
                $this->vocabularySubject(501, '上げる', ['あげる'], ['To Raise']),
                $this->studySubject(502, [
                    'type' => 'kana_vocabulary',
                    'characters' => 'おやつ',
                    'readings' => [],
                    'meanings' => ['Snack'],
                ]),
                $this->vocabularySubject(503, '私', ['わたし'], ['I']),
            ]));
        });
    }

    private function assertIncrementalSyncState(int $userId): void
    {
        $this->assertDatabaseHas('user_known_kanji', [
            'user_id' => $userId,
            'character' => '一',
            'wanikani_subject_id' => 440,
        ]);
        $this->assertDatabaseMissing('user_known_kanji', [
            'user_id' => $userId,
            'wanikani_subject_id' => 441,
        ]);
        $this->assertDatabaseHas('user_wanikani_assignments', [
            'user_id' => $userId,
            'subject_id' => 500,
            'srs_stage' => 2,
        ]);
        $this->assertNotNull(DB::table('user_wanikani_assignments')
            ->where('user_id', $userId)
            ->where('subject_id', 500)
            ->value('passed_at'));
        $this->assertDatabaseHas('user_wanikani_assignments', [
            'user_id' => $userId,
            'subject_id' => 501,
            'passed_at' => null,
        ]);
        $this->assertDatabaseHas('wanikani_subject_learning_concepts', [
            'subject_id' => 500,
            'concept_id' => 'n5-vocab-2013900-2dacb910',
            'matcher_version' => 'wanikani-exact-v2',
        ]);
        $this->assertDatabaseHas('wanikani_subject_learning_concepts', [
            'subject_id' => 503,
            'concept_id' => 'n5-vocab-1311110-8b82a027',
            'match_method' => 'expression_reading',
        ]);
        $this->assertDatabaseMissing('wanikani_subject_learning_concepts', [
            'subject_id' => 502,
        ]);
    }

    private function assertIncrementalSyncRequests(): void
    {
        $assignmentRequests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request): bool => str_contains($request->url(), '/assignments'))
            ->values();
        $kanjiAssignmentRequests = $assignmentRequests->filter(
            fn ($request): bool => str_contains($request->url(), 'subject_types=kanji'),
        )->values();
        $vocabularyAssignmentRequests = $assignmentRequests->filter(
            fn ($request): bool => str_contains($request->url(), 'subject_types=vocabulary%2Ckana_vocabulary'),
        )->values();
        $reviewCountRequests = $assignmentRequests->filter(
            fn ($request): bool => str_contains($request->url(), 'immediately_available_for_review=true'),
        )->values();
        $this->assertCount(2, $kanjiAssignmentRequests);
        $this->assertCount(2, $vocabularyAssignmentRequests);
        $this->assertCount(2, $reviewCountRequests);
        $this->assertStringNotContainsString('updated_after=', $kanjiAssignmentRequests[0]->url());
        $this->assertStringContainsString('updated_after=', $kanjiAssignmentRequests[1]->url());
        $this->assertStringNotContainsString('updated_after=', $vocabularyAssignmentRequests[0]->url());
        $this->assertStringContainsString('updated_after=', $vocabularyAssignmentRequests[1]->url());
    }

    private function assertReviewCountResponse(int $userId): void
    {
        $connection = WaniKaniConnection::query()->where('user_id', $userId)->firstOrFail();
        $this->getJson('/api/study/known-kanji')
            ->assertJsonPath('wanikani.reviewCount', 9)
            ->assertJsonPath(
                'wanikani.reviewCountUpdatedAt',
                $connection->review_count_updated_at->toJSON(),
            );
    }
}
