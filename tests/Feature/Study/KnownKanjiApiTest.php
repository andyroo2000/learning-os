<?php

namespace Tests\Feature\Study;

use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnownKanjiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_kanji_requires_authentication(): void
    {
        $this->getJson('/api/study/known-kanji')->assertUnauthorized();
        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => true])->assertUnauthorized();
    }

    public function test_empty_known_kanji_response_does_not_materialize_state(): void
    {
        $this->signIn();

        $this->getJson('/api/study/known-kanji')
            ->assertOk()
            ->assertExactJson([
                'version' => 0,
                'kanji' => [],
                'manualKanji' => [],
                'wanikani' => [
                    'connected' => false,
                    'lastSyncedAt' => null,
                    'reviewCount' => null,
                    'reviewCountUpdatedAt' => null,
                ],
            ]);

        $this->assertDatabaseCount('japanese_knowledge_profiles', 0);
    }

    public function test_manual_known_kanji_updates_the_effective_set_and_version(): void
    {
        $user = $this->signIn();

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => true])
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('kanji.0', '私')
            ->assertJsonPath('manualKanji.0', '私');

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => true])
            ->assertOk()
            ->assertJsonPath('version', 1);

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => false])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('kanji', []);

        $this->assertDatabaseMissing('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '私',
        ]);
    }

    #[DataProvider('invalidManualKanjiProvider')]
    public function test_manual_known_kanji_rejects_non_kanji_values(mixed $kanji): void
    {
        $this->signIn();

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => $kanji, 'known' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kanji');
    }

    public static function invalidManualKanjiProvider(): array
    {
        return [
            'kana' => ['わ'],
            'multiple kanji' => ['会社'],
            'iteration mark' => ['々'],
            'array' => [['私']],
            'blank' => [''],
        ];
    }

    public function test_connect_validates_then_encrypts_the_api_token(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response(['object' => 'user'], 200)]);

        $this->putJson('/api/study/wanikani', ['apiToken' => ' test-token '])
            ->assertOk()
            ->assertJsonPath('wanikani.connected', true);

        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('test-token', $connection->api_token);
        $this->assertNotSame('test-token', $connection->getRawOriginal('api_token'));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_connect_rejects_a_token_wanikani_does_not_accept_without_persisting_it(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response([], 401)]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'bad-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apiToken');

        $this->assertDatabaseMissing('wanikani_connections', ['user_id' => $user->id]);
    }

    public function test_disconnect_is_idempotent_and_preserves_ever_known_kanji(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response(['object' => 'user'])]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $knownKanji = new UserKnownKanji;
        $knownKanji->user_id = $user->id;
        $knownKanji->character = '私';
        $knownKanji->wanikani_subject_id = 999;
        $knownKanji->wanikani_passed_at = now();
        $knownKanji->save();

        $this->deleteJson('/api/study/wanikani')->assertNoContent();
        $this->deleteJson('/api/study/wanikani')->assertNoContent();

        $this->getJson('/api/study/known-kanji')
            ->assertOk()
            ->assertJsonPath('wanikani.connected', false)
            ->assertJsonPath('kanji.0', '私');

        $this->assertDatabaseMissing('wanikani_connections', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '私',
            'wanikani_subject_id' => 999,
        ]);
    }

    public function test_connect_reports_wanikani_outages_without_persisting_the_token(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response([], 503)]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'WaniKani is temporarily unavailable.']);

        $this->assertDatabaseMissing('wanikani_connections', ['user_id' => $user->id]);
    }

    public function test_changing_tokens_resets_sync_cursors_but_reusing_the_token_does_not(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response(['object' => 'user'])]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'first-token'])->assertOk();
        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $connection->assignments_synced_through_at = now()->subHour();
        $connection->vocabulary_assignments_synced_through_at = now()->subHour();
        $connection->last_synced_at = now()->subHour();
        $connection->review_count = 12;
        $connection->review_count_updated_at = now()->subHour();
        $connection->save();

        $this->putJson('/api/study/wanikani', ['apiToken' => 'first-token'])->assertOk();
        $this->assertNotNull($connection->fresh()->last_synced_at);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'second-token'])->assertOk();
        $connection->refresh();
        $this->assertNull($connection->assignments_synced_through_at);
        $this->assertNull($connection->vocabulary_assignments_synced_through_at);
        $this->assertNull($connection->last_synced_at);
        $this->assertNull($connection->review_count);
        $this->assertNull($connection->review_count_updated_at);
    }

    public function test_sync_persists_ever_passed_kanji_and_vocabulary_with_separate_incremental_cursors(): void
    {
        $user = $this->signIn();
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
                $this->vocabularySubject(500, 'vocabulary', '赤', ['あか'], ['Red']),
                $this->vocabularySubject(501, 'vocabulary', '上げる', ['あげる'], ['To Raise']),
                $this->vocabularySubject(502, 'kana_vocabulary', 'おやつ', [], ['Snack']),
                $this->vocabularySubject(503, 'vocabulary', '私', ['わたし'], ['I']),
            ]));
        });

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

        $this->assertDatabaseHas('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '一',
            'wanikani_subject_id' => 440,
        ]);
        $this->assertDatabaseMissing('user_known_kanji', [
            'user_id' => $user->id,
            'wanikani_subject_id' => 441,
        ]);
        $this->assertDatabaseHas('user_wanikani_assignments', [
            'user_id' => $user->id,
            'subject_id' => 500,
            'srs_stage' => 2,
        ]);
        $this->assertNotNull(DB::table('user_wanikani_assignments')
            ->where('user_id', $user->id)
            ->where('subject_id', 500)
            ->value('passed_at'));
        $this->assertDatabaseHas('user_wanikani_assignments', [
            'user_id' => $user->id,
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
        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $this->getJson('/api/study/known-kanji')
            ->assertJsonPath('wanikani.reviewCount', 9)
            ->assertJsonPath(
                'wanikani.reviewCountUpdatedAt',
                $connection->review_count_updated_at->toJSON(),
            );
    }

    public function test_sync_preserves_vocabulary_filters_from_wanikani_pagination_urls(): void
    {
        $this->signIn();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/user')) {
                return Http::response(['object' => 'user']);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/assignments')) {
                if (($query['immediately_available_for_review'] ?? null) === 'true') {
                    return Http::response($this->assignmentCollection([], totalCount: 0));
                }
                if (($query['subject_types'] ?? null) === 'kanji') {
                    return Http::response($this->assignmentCollection([]));
                }
                if (($query['subject_types'] ?? null) !== 'vocabulary,kana_vocabulary') {
                    return Http::response($this->assignmentCollection([
                        $this->assignment(1, 'radical', 8, '2026-07-15T12:00:00.000000Z'),
                    ]));
                }
                if (($query['page_after_id'] ?? null) === '500') {
                    return Http::response($this->assignmentCollection([
                        $this->assignment(501, 'vocabulary', 5, '2026-07-16T12:00:00.000000Z'),
                    ]));
                }

                return Http::response([
                    'object' => 'collection',
                    'pages' => [
                        'next_url' => 'https://api.wanikani.com/v2/assignments'
                            .'?subject_types=vocabulary%2Ckana_vocabulary&page_after_id=500',
                    ],
                    'total_count' => 2,
                    'data' => [
                        $this->assignment(500, 'vocabulary', 5, '2026-07-15T12:00:00.000000Z'),
                    ],
                ]);
            }

            return Http::response($this->subjectCollection([
                $this->vocabularySubject(500, 'vocabulary', '赤', ['あか'], ['Red']),
                $this->vocabularySubject(501, 'vocabulary', '青', ['あお'], ['Blue']),
            ]));
        });

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson([
                'added' => 0,
                'effectiveTotal' => 0,
                'version' => 0,
                'reviewCount' => 0,
                'vocabularyAdded' => 2,
                'vocabularyKnownTotal' => 2,
                'vocabularyMatchedTotal' => 2,
            ]);
        $this->assertDatabaseHas('user_wanikani_assignments', ['subject_id' => 500]);
        $this->assertDatabaseHas('user_wanikani_assignments', ['subject_id' => 501]);
        $this->assertDatabaseMissing('user_wanikani_assignments', ['subject_id' => 1]);

        $vocabularyRequests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request): bool => str_contains($request->url(), '/assignments'))
            ->filter(function ($request): bool {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($query['subject_types'] ?? null) === 'vocabulary,kana_vocabulary';
            })
            ->values();
        $this->assertCount(2, $vocabularyRequests);
        parse_str(
            (string) parse_url($vocabularyRequests[1]->url(), PHP_URL_QUERY),
            $secondPageQuery,
        );
        $this->assertSame('vocabulary,kana_vocabulary', $secondPageQuery['subject_types'] ?? null);
        $this->assertSame('500', $secondPageQuery['page_after_id'] ?? null);
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

    #[DataProvider('wanikaniProcessOwnedSummaryFieldProvider')]
    public function test_wanikani_process_owned_summary_fields_are_mass_assignment_guarded(
        string $field,
        mixed $value,
    ): void {
        $this->expectException(MassAssignmentException::class);

        (new WaniKaniConnection)->fill([$field => $value]);
    }

    /** @return array<string, array{string, mixed}> */
    public static function wanikaniProcessOwnedSummaryFieldProvider(): array
    {
        return [
            'review count' => ['review_count', 12],
            'review count timestamp' => ['review_count_updated_at', '2026-08-24T18:00:00Z'],
        ];
    }

    public function test_sync_rejects_malformed_wanikani_vocabulary_without_advancing_cursors(): void
    {
        $user = $this->signIn();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/user')) {
                return Http::response(['object' => 'user']);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/assignments')) {
                return Http::response($this->assignmentCollection(
                    ($query['subject_types'] ?? null) === 'kanji'
                        ? []
                        : [$this->assignment(500, 'vocabulary', 5, '2026-07-16T12:00:00.000000Z')],
                ));
            }

            return Http::response($this->subjectCollection([
                $this->vocabularySubject(500, 'vocabulary', '赤', [], ['Red']),
            ]));
        });

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $this->postJson('/api/study/wanikani/sync')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'WaniKani returned an unexpected response.']);

        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($connection->assignments_synced_through_at);
        $this->assertNull($connection->vocabulary_assignments_synced_through_at);
        $this->assertNull($connection->last_synced_at);
        $this->assertDatabaseCount('wanikani_subjects', 0);
        $this->assertDatabaseCount('user_wanikani_assignments', 0);
    }

    public function test_sync_rematches_stored_subjects_when_the_matcher_catalog_version_changes(): void
    {
        $user = $this->signIn();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/user')) {
                return Http::response(['object' => 'user']);
            }

            return Http::response($this->assignmentCollection([]));
        });
        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $now = now();
        DB::table('wanikani_subjects')->insert([
            'subject_id' => 900,
            'subject_type' => 'vocabulary',
            'characters' => '赤',
            'normalized_key' => '赤',
            'readings' => json_encode(['あか'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'meanings' => json_encode(['Red'], JSON_THROW_ON_ERROR),
            'matcher_version' => 'wanikani-exact-v0',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_wanikani_assignments')->insert([
            'user_id' => $user->id,
            'subject_id' => 900,
            'srs_stage' => 5,
            'passed_at' => $now,
            'hidden' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson([
                'added' => 0,
                'effectiveTotal' => 0,
                'version' => 0,
                'reviewCount' => 0,
                'vocabularyAdded' => 0,
                'vocabularyKnownTotal' => 1,
                'vocabularyMatchedTotal' => 1,
            ]);

        $this->assertDatabaseHas('wanikani_subjects', [
            'subject_id' => 900,
            'matcher_version' => 'wanikani-exact-v2',
        ]);
        $this->assertDatabaseHas('wanikani_subject_learning_concepts', [
            'subject_id' => 900,
            'concept_id' => 'n5-vocab-2013900-2dacb910',
            'matcher_version' => 'wanikani-exact-v2',
        ]);
    }

    public function test_removing_a_manual_marker_keeps_wanikani_evidence_known(): void
    {
        $user = $this->signIn();
        $knownKanji = new UserKnownKanji;
        $knownKanji->user_id = $user->id;
        $knownKanji->character = '私';
        $knownKanji->wanikani_subject_id = 999;
        $knownKanji->wanikani_passed_at = now();
        $knownKanji->manually_added_at = now();
        $knownKanji->save();

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => false])
            ->assertOk()
            ->assertJsonPath('version', 0)
            ->assertJsonPath('kanji.0', '私')
            ->assertJsonPath('manualKanji', []);
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

    private function assignment(
        int $subjectId,
        string $subjectType,
        int $srsStage,
        ?string $passedAt,
    ): array {
        return [
            'object' => 'assignment',
            'data_updated_at' => '2026-07-18T12:00:00.000000Z',
            'data' => [
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'srs_stage' => $srsStage,
                'passed_at' => $passedAt,
                'burned_at' => null,
                'hidden' => false,
            ],
        ];
    }

    private function assignmentCollection(array $assignments, ?int $totalCount = null): array
    {
        return [
            'object' => 'collection',
            'pages' => ['next_url' => null],
            'total_count' => $totalCount ?? count($assignments),
            'data' => $assignments,
        ];
    }

    private function kanjiSubject(int $id, string $character): array
    {
        return ['id' => $id, 'object' => 'kanji', 'data' => ['characters' => $character]];
    }

    /** @param list<string> $readings
     * @param  list<string>  $meanings
     */
    private function vocabularySubject(
        int $id,
        string $type,
        string $characters,
        array $readings,
        array $meanings,
    ): array {
        return [
            'id' => $id,
            'object' => $type,
            'data_updated_at' => '2026-07-18T12:00:00.000000Z',
            'data' => [
                'characters' => $characters,
                'readings' => array_map(
                    fn (string $reading): array => ['reading' => $reading, 'accepted_answer' => true],
                    $readings,
                ),
                'meanings' => array_map(
                    fn (string $meaning): array => ['meaning' => $meaning, 'accepted_answer' => true],
                    $meanings,
                ),
                'hidden_at' => null,
            ],
        ];
    }

    private function subjectCollection(array $subjects): array
    {
        return ['object' => 'collection', 'pages' => ['next_url' => null], 'data' => $subjects];
    }
}
