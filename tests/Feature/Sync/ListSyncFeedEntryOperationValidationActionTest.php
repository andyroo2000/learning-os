<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListSyncFeedEntryOperationValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_operation_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed operation must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            operation: ' ',
        );
    }

    public function test_it_rejects_unknown_operation_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed operation must be one of: create, update, delete.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            operation: 'patch',
        );
    }
}
