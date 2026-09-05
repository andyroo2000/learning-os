<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListSyncFeedEntryResourceIdValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_resource_type_filters_above_the_maximum_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_type must not exceed '.SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH.' characters.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceType: str_repeat('a', SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH + 1),
        );
    }

    public function test_it_rejects_blank_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: 'flashcards',
            resourceType: 'card',
            resourceId: ' ',
        );
    }

    public function test_it_rejects_resource_id_filters_above_the_maximum_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id must not exceed '.SyncFeedEntry::MAX_RESOURCE_ID_LENGTH.' characters.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: 'flashcards',
            resourceType: 'card',
            resourceId: str_repeat('a', SyncFeedEntry::MAX_RESOURCE_ID_LENGTH + 1),
        );
    }
}
