<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ListStudyBrowserAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListStudyBrowserValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_note_type_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser noteType filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            noteType: '   ',
        );
    }

    public function test_it_rejects_blank_search_queries_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card search query filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            q: '   ',
        );
    }

    public function test_it_rejects_blank_course_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser courseId filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            courseId: '   ',
        );
    }

    public function test_it_rejects_malformed_course_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser courseId filter must be a valid ULID.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            courseId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_blank_deck_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser deckId filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            deckId: '   ',
        );
    }

    public function test_it_rejects_malformed_deck_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser deckId filter must be a valid ULID.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            deckId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_invalid_sort_controls_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser sortField must be one of:');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            sortField: 'last_seen',
        );
    }

    public function test_it_rejects_invalid_sort_directions_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser sortDirection must be one of:');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            sortDirection: 'sideways',
        );
    }

    public function test_it_uses_the_default_limit_for_direct_callers_when_limit_is_absent(): void
    {
        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
        );

        $this->assertSame(ListStudyBrowserAction::DEFAULT_LIMIT, $result['limit']);
    }

    public function test_it_accepts_boundary_limits_for_direct_callers(): void
    {
        $user = $this->signIn();

        $minimum = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            limit: 1,
        );
        $maximum = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            limit: ListStudyBrowserAction::MAX_LIMIT,
        );

        $this->assertSame(1, $minimum['limit']);
        $this->assertSame(ListStudyBrowserAction::MAX_LIMIT, $maximum['limit']);
    }

    public function test_it_rejects_invalid_limits_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyBrowserAction::MAX_LIMIT.'.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            limit: 0,
        );
    }

    public function test_it_rejects_negative_limits_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyBrowserAction::MAX_LIMIT.'.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            limit: -1,
        );
    }

    public function test_it_rejects_over_max_limits_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyBrowserAction::MAX_LIMIT.'.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            limit: ListStudyBrowserAction::MAX_LIMIT + 1,
        );
    }

    public function test_it_rejects_invalid_direct_cursors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser cursor is invalid.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            cursor: 'not-a-cursor',
        );
    }
}
