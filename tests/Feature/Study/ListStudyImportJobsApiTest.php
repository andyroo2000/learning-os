<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

class ListStudyImportJobsApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/imports')->assertUnauthorized();
    }

    public function test_index_returns_import_jobs_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $olderImport = StudyImportJob::factory()->for($user)->create([
            'source_filename' => 'older.colpkg',
            'source_object_path' => 'study/imports/internal/older.colpkg',
            'deck_name' => 'Older Deck',
            'updated_at' => now()->subDay(),
            'preview_json' => [
                'deck_name' => 'Older Deck',
                'card_count' => 10,
            ],
        ]);
        $newerImport = StudyImportJob::factory()->completed()->for($user)->create([
            'source_filename' => 'newer.colpkg',
            'deck_name' => 'Newer Deck',
            'summary_json' => [
                'imported_cards' => 8,
            ],
            'updated_at' => now(),
        ]);
        $otherImport = StudyImportJob::factory()->for(User::factory()->create())->create([
            'updated_at' => now()->addDay(),
        ]);

        $this->getJson('/api/study/imports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newerImport->id)
            ->assertJsonPath('data.0.status', StudyImportStatus::Completed->value)
            ->assertJsonPath('data.0.source_filename', 'newer.colpkg')
            ->assertJsonPath('data.0.deck_name', 'Newer Deck')
            ->assertJsonPath('data.0.summary.imported_cards', 8)
            ->assertJsonPath('data.1.id', $olderImport->id)
            ->assertJsonPath('data.1.status', StudyImportStatus::Pending->value)
            ->assertJsonPath('data.1.preview.card_count', 10)
            ->assertJsonMissingPath('data.0.source_object_path')
            ->assertJsonMissing([
                'id' => $otherImport->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'status',
                        'source_type',
                        'source_filename',
                        'source_content_type',
                        'source_size_bytes',
                        'deck_name',
                        'preview',
                        'summary',
                        'error_message',
                        'started_at',
                        'uploaded_at',
                        'upload_completed_at',
                        'upload_expires_at',
                        'completed_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_index_preserves_query_string_across_cursor_pages(): void
    {
        $user = $this->signIn();
        $olderImport = StudyImportJob::factory()->for($user)->create([
            'updated_at' => now()->subDay(),
        ]);
        $newerImport = StudyImportJob::factory()->for($user)->create([
            'updated_at' => now(),
        ]);

        $firstPage = $this->getJson('/api/study/imports?per_page=1');

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerImport->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '1');

        $this->getJson($this->pathAndQueryFromUrl($nextUrl))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderImport->id);
    }

    public function test_index_filters_import_jobs_by_status(): void
    {
        $user = $this->signIn();
        $completedImport = StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now(),
        ]);
        $pendingImport = StudyImportJob::factory()->for($user)->create([
            'updated_at' => now()->addMinute(),
        ]);
        $otherUserCompletedImport = StudyImportJob::factory()->completed()->for(User::factory()->create())->create([
            'updated_at' => now()->addMinutes(2),
        ]);

        $this->getJson('/api/study/imports?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completedImport->id)
            ->assertJsonPath('data.0.status', StudyImportStatus::Completed->value)
            ->assertJsonMissing([
                'id' => $pendingImport->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUserCompletedImport->id,
            ]);
    }

    public function test_index_normalizes_status_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $completedImport = StudyImportJob::factory()->completed()->for($user)->create();
        $pendingImport = StudyImportJob::factory()->for($user)->create();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/imports?status=%20COMPLETED%20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completedImport->id)
            ->assertJsonMissing([
                'id' => $pendingImport->id,
            ]);
    }

    public function test_index_rejects_blank_malformed_and_array_status_filters(): void
    {
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/imports?status=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/study/imports?status=queued')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/study/imports?status[]=completed')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_index_preserves_status_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $olderImport = StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now()->subDay(),
        ]);
        $newerImport = StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now(),
        ]);
        $pendingImport = StudyImportJob::factory()->for($user)->create([
            'updated_at' => now()->addDay(),
        ]);

        $firstPage = $this->getJson('/api/study/imports?status=completed&per_page=1');

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerImport->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'status', 'completed');
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '1');

        $this->getJson($this->pathAndQueryFromUrl($nextUrl))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderImport->id)
            ->assertJsonMissing([
                'id' => $pendingImport->id,
            ]);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();

        StudyImportJob::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/study/imports');
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();

        StudyImportJob::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointUsesDefaultPageSize('/api/study/imports');
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();

        StudyImportJob::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize('/api/study/imports');
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();

        StudyImportJob::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize('/api/study/imports');
    }

    public function test_it_rejects_invalid_page_sizes(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/study/imports', CursorPagination::MAX_PAGE_SIZE + 1);
        $this->assertCursorEndpointRejectsPageSize('/api/study/imports', 0);
        $this->assertCursorEndpointRejectsPageSize('/api/study/imports', -1);
        $this->assertCursorEndpointRejectsPageSize('/api/study/imports', 'abc');
        $this->assertCursorEndpointRejectsArrayPageSize('/api/study/imports');
    }

    public function test_it_rejects_a_blank_page_size_without_global_trim_middleware(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsBlankPageSizeWithoutTrimMiddleware('/api/study/imports');
    }

    public function test_it_rejects_invalid_cursor_values(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsMalformedCursor('/api/study/imports');
        $this->assertCursorEndpointRejectsArrayCursor('/api/study/imports');
        $this->assertCursorEndpointRejectsParameterlessCursor('/api/study/imports');
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE - 1) as $index) {
            StudyImportJob::factory()->for($user)->create([
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieImport = StudyImportJob::factory()->for($user)->create([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh36',
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/study/imports');

        $firstPage
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieImport->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $this->getJson("/api/study/imports?cursor={$nextCursor}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieImport->id)
            ->assertJsonPath('meta.next_cursor', null);
    }
}
