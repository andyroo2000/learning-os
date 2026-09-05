<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListSyncFeedEntryFilterValueValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_domain_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed domain must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: ' ',
        );
    }

    public function test_it_rejects_domain_filters_above_the_maximum_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed domain must not exceed '.SyncFeedEntry::MAX_DOMAIN_LENGTH.' characters.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: str_repeat('a', SyncFeedEntry::MAX_DOMAIN_LENGTH + 1),
        );
    }

    public function test_it_rejects_blank_resource_type_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_type must not be blank when provided.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceType: ' ',
        );
    }
}
