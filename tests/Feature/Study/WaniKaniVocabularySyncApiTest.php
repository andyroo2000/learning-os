<?php

namespace Tests\Feature\Study;

use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Study\BuildsWaniKaniApiResponses;
use Tests\TestCase;

class WaniKaniVocabularySyncApiTest extends TestCase
{
    use BuildsWaniKaniApiResponses;
    use RefreshDatabase;

    public function test_sync_queues_a_recent_vocabulary_import_when_the_bridge_is_enabled(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $passedAt = now()->subHour()->toJSON();
        Http::fake(function ($request) use ($passedAt) {
            $url = $request->url();
            if (str_ends_with($url, '/user')) {
                return Http::response(['object' => 'user']);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/assignments')) {
                if (($query['immediately_available_for_review'] ?? null) === 'true'
                    || ($query['subject_types'] ?? null) === 'kanji') {
                    return Http::response($this->assignmentCollection([]));
                }

                return Http::response($this->assignmentCollection([
                    $this->assignment(550, 'vocabulary', 5, $passedAt),
                ]));
            }

            return Http::response($this->subjectCollection([
                $this->vocabularySubject(550, '会社', ['かいしゃ'], ['Company']),
            ]));
        });

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();
        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])->assertOk();
        $this->postJson('/api/study/wanikani/sync')->assertOk();

        $this->assertDatabaseHas('study_vocab_variant_groups', [
            'user_id' => $user->id,
            'wanikani_subject_id' => 550,
            'automatic_import_status' => AutomaticStudyVocabImportStatus::Generating->value,
        ]);
        Queue::assertPushed(
            ProcessStudyVocabBundleDrafts::class,
            fn (ProcessStudyVocabBundleDrafts $job): bool => $job->groupId !== '',
        );
    }

    public function test_sync_preserves_vocabulary_filters_from_wanikani_pagination_urls(): void
    {
        $this->signIn();
        $this->fakePaginatedVocabularyResponses();

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

        $this->assertVocabularyPaginationRequests();
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
                $this->vocabularySubject(500, '赤', [], ['Red']),
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

    private function fakePaginatedVocabularyResponses(): void
    {
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
                $this->vocabularySubject(500, '赤', ['あか'], ['Red']),
                $this->vocabularySubject(501, '青', ['あお'], ['Blue']),
            ]));
        });
    }

    private function assertVocabularyPaginationRequests(): void
    {
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
}
