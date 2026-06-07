<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ListStudyCardDraftsAction;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftCursor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ListStudyCardDraftsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_user_drafts_in_stable_created_order(): void
    {
        $user = User::factory()->create();
        $olderDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now()->subDay(),
        ]);
        $newerDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now(),
        ]);
        StudyCardDraft::factory()->for(User::factory()->create())->create([
            'created_at' => now()->subDays(2),
        ]);

        $result = app(ListStudyCardDraftsAction::class)->handle($user->id);

        $this->assertSame(2, $result['total']);
        $this->assertSame(ListStudyCardDraftsAction::DEFAULT_LIMIT, $result['limit']);
        $this->assertNull($result['nextCursor']);
        $this->assertSame([$olderDraft->id, $newerDraft->id], $result['drafts']->pluck('id')->all());
    }

    public function test_it_returns_an_empty_first_page_for_users_without_drafts(): void
    {
        $result = app(ListStudyCardDraftsAction::class)->handle(User::factory()->create()->id);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['drafts']->all());
        $this->assertNull($result['nextCursor']);
    }

    public function test_it_returns_the_next_cursor_after_the_page_boundary(): void
    {
        $user = User::factory()->create();
        $olderDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now()->subDay(),
        ]);
        $newerDraft = StudyCardDraft::factory()->for($user)->create([
            'created_at' => now(),
        ]);

        $firstPage = app(ListStudyCardDraftsAction::class)->handle($user->id, limit: 1);
        $this->assertSame([$olderDraft->id], $firstPage['drafts']->pluck('id')->all());
        $this->assertNotNull($firstPage['nextCursor']);

        $secondPage = app(ListStudyCardDraftsAction::class)->handle(
            userId: $user->id,
            cursor: $firstPage['nextCursor'],
            limit: 1,
        );

        $this->assertSame([$newerDraft->id], $secondPage['drafts']->pluck('id')->all());
        $this->assertNull($secondPage['total']);
        $this->assertNull($secondPage['nextCursor']);
    }

    public function test_it_skips_the_total_count_on_cursor_pages(): void
    {
        $user = User::factory()->create();
        StudyCardDraft::factory()->for($user)->create([
            'created_at' => now()->subDay(),
        ]);
        StudyCardDraft::factory()->for($user)->create([
            'created_at' => now(),
        ]);

        $firstPage = app(ListStudyCardDraftsAction::class)->handle($user->id, limit: 1);
        $this->assertSame(2, $firstPage['total']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $secondPage = app(ListStudyCardDraftsAction::class)->handle(
                userId: $user->id,
                cursor: $firstPage['nextCursor'],
                limit: 1,
            );
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertNull($secondPage['total']);
        $this->assertFalse(
            $queries->contains(fn (array $query): bool => str_contains(strtolower($query['query']), 'count(*) as aggregate')),
            'Cursor pages should not run the stable total count query.',
        );
    }

    public function test_it_rejects_invalid_cursor_and_limit_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid study card draft cursor.');

        app(ListStudyCardDraftsAction::class)->handle(User::factory()->create()->id, cursor: 'not-a-cursor');
    }

    public function test_it_rejects_invalid_limit_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyCardDraftsAction::MAX_LIMIT.'.');

        app(ListStudyCardDraftsAction::class)->handle(User::factory()->create()->id, limit: 0);
    }

    public function test_cursor_round_trips_created_at_and_id(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'created_at' => now()->subMinute(),
        ]);

        $decoded = StudyCardDraftCursor::decode(StudyCardDraftCursor::encode($draft));

        $this->assertSame($draft->created_at->copy()->startOfSecond()->toJSON(), $decoded['created_at']->toJSON());
        $this->assertSame($draft->id, $decoded['id']);
    }

    public function test_cursor_encodes_created_at_at_second_precision(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'created_at' => now()->subMinute()->setMicrosecond(123456),
        ]);

        $decoded = StudyCardDraftCursor::decode(StudyCardDraftCursor::encode($draft));

        $this->assertSame(0, $decoded['created_at']->microsecond);
    }

    public function test_cursor_encode_rejects_unpersisted_drafts(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft cursor requires a persisted draft with timestamps.');

        StudyCardDraftCursor::encode(new StudyCardDraft);
    }

    public function test_cursor_normalizes_uppercase_ids(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'created_at' => now()->subMinute(),
        ]);
        $cursor = rtrim(strtr(base64_encode(json_encode([
            'created_at' => $draft->created_at->toJSON(),
            'id' => strtoupper($draft->id),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $decoded = StudyCardDraftCursor::decode($cursor);

        $this->assertSame($draft->id, $decoded['id']);
    }

    public function test_cursor_rejects_empty_created_at(): void
    {
        $cursor = rtrim(strtr(base64_encode(json_encode([
            'created_at' => '',
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid study card draft cursor.');

        StudyCardDraftCursor::decode($cursor);
    }

    public function test_cursor_rejects_relative_created_at(): void
    {
        $cursor = rtrim(strtr(base64_encode(json_encode([
            'created_at' => 'tomorrow',
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid study card draft cursor.');

        StudyCardDraftCursor::decode($cursor);
    }

    public function test_cursor_rejects_invalid_id(): void
    {
        $cursor = rtrim(strtr(base64_encode(json_encode([
            'created_at' => now()->toJSON(),
            'id' => 'not-a-ulid',
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid study card draft cursor.');

        StudyCardDraftCursor::decode($cursor);
    }
}
