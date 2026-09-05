<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ListSyncFeedEntryResourceScopeValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_domain_and_resource_type_for_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id filters require both domain and resource_type.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceId: 'card-1',
        );
    }

    public function test_it_requires_domain_for_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id filters require both domain and resource_type.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            resourceType: 'card',
            resourceId: 'card-1',
        );
    }

    public function test_it_requires_resource_type_for_resource_id_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed resource_id filters require both domain and resource_type.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: 'flashcards',
            resourceId: 'card-1',
        );
    }

    /**
     * @return array<string, array{filters: array<string, string>, message: string}>
     */
}
