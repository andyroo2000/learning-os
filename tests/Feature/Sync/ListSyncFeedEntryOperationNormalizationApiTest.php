<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryOperationNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_trims_the_operation_filter(): void
    {
        $user = $this->signIn();
        $delete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?operation=%20delete%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.checkpoint', $delete->checkpoint);
    }

    public function test_it_normalizes_the_operation_filter_case(): void
    {
        $user = $this->signIn();
        $delete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?operation=DELETE');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.checkpoint', $delete->checkpoint);
    }
}
