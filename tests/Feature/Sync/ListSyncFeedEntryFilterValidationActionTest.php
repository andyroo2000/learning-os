<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListSyncFeedEntryFilterValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_filter_values_at_the_maximum_length(): void
    {
        $user = User::factory()->create();

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: str_repeat('a', SyncFeedEntry::MAX_DOMAIN_LENGTH),
            resourceType: str_repeat('b', SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH),
            resourceId: str_repeat('c', SyncFeedEntry::MAX_RESOURCE_ID_LENGTH),
        );

        $this->assertTrue($result->entries->isEmpty());
        $this->assertFalse($result->hasMore);
    }

    public function test_it_accepts_multibyte_filter_values_at_the_maximum_length(): void
    {
        $user = User::factory()->create();
        $domain = str_repeat(mb_chr(0x754C), SyncFeedEntry::MAX_DOMAIN_LENGTH);
        $resourceType = str_repeat(mb_chr(0x7A2E), SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH);
        $resourceId = str_repeat(mb_chr(0x8B58), SyncFeedEntry::MAX_RESOURCE_ID_LENGTH);
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => $domain,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);

        $result = app(ListSyncFeedEntriesAction::class)->handle(
            userId: $user->id,
            domain: $domain,
            resourceType: $resourceType,
            resourceId: $resourceId,
        );

        $this->assertSame([$entry->checkpoint], $result->entries->pluck('checkpoint')->all());
        $this->assertFalse($result->hasMore);
    }

    #[DataProvider('multibyteOverlongFilterProvider')]
    public function test_it_rejects_multibyte_filter_values_above_the_maximum_length(array $filters, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        app(ListSyncFeedEntriesAction::class)->handle(
            userId: 1,
            domain: $filters['domain'] ?? null,
            resourceType: $filters['resourceType'] ?? null,
            resourceId: $filters['resourceId'] ?? null,
        );
    }

    public static function multibyteOverlongFilterProvider(): array
    {
        return [
            'domain' => [
                'filters' => [
                    'domain' => str_repeat(mb_chr(0x754C), SyncFeedEntry::MAX_DOMAIN_LENGTH + 1),
                ],
                'message' => 'Sync feed domain must not exceed '.SyncFeedEntry::MAX_DOMAIN_LENGTH.' characters.',
            ],
            'resource type' => [
                'filters' => [
                    'resourceType' => str_repeat(mb_chr(0x7A2E), SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH + 1),
                ],
                'message' => 'Sync feed resource_type must not exceed '.SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH.' characters.',
            ],
            'resource id' => [
                'filters' => [
                    // resourceId requires valid scope companions; keep this case focused on max-length rejection.
                    'domain' => 'x',
                    'resourceType' => 'x',
                    'resourceId' => str_repeat(mb_chr(0x8B58), SyncFeedEntry::MAX_RESOURCE_ID_LENGTH + 1),
                ],
                'message' => 'Sync feed resource_id must not exceed '.SyncFeedEntry::MAX_RESOURCE_ID_LENGTH.' characters.',
            ],
        ];
    }
}
