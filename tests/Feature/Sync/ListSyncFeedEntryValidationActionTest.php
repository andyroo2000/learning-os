<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ListSyncFeedEntryValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_non_positive_user_id(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Sync feed user ID must be a positive integer.');

        app(ListSyncFeedEntriesAction::class)->handle(0);
    }

    public function test_it_rejects_negative_checkpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sync feed checkpoint must be zero or greater.');

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            afterCheckpoint: -1,
        );
    }
}
