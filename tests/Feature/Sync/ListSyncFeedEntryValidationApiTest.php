<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListSyncFeedEntryValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?per_page='.(CursorPagination::MAX_PAGE_SIZE + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?per_page=0');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_page_sizes(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?per_page[]=10');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_negative_checkpoints(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?after_checkpoint=-1');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_non_integer_checkpoints(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?after_checkpoint=abc');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_blank_pagination_inputs_without_trim_strings_middleware(): void
    {
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?after_checkpoint=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['after_checkpoint']);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?per_page=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_it_rejects_array_checkpoints(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?after_checkpoint[]=1');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_blank_domain_filters(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?domain=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_domain_filters_above_the_maximum_length(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain='.str_repeat('a', SyncFeedEntry::MAX_DOMAIN_LENGTH + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_domain_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain[]=flashcards');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_blank_resource_type_filters(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?resource_type=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_resource_type_filters_above_the_maximum_length(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type='.str_repeat('a', SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_resource_type_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type[]=card');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_unknown_operation_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?operation=patch');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_blank_operation_filters(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?operation=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_operation_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?operation[]=delete');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_resource_id_without_domain_and_resource_type_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_id=card-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain', 'resource_type']);
    }

    public function test_it_rejects_resource_id_without_resource_type_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_id=card-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_type']);
    }

    public function test_it_rejects_resource_id_without_domain_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type=card&resource_id=card-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    }

    public function test_it_rejects_blank_resource_id_filters(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_resource_id_filters_above_the_maximum_length(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id='.str_repeat('a', SyncFeedEntry::MAX_RESOURCE_ID_LENGTH + 1));

        $response->assertUnprocessable();
    }

    #[DataProvider('multibyteOverlongFilterProvider')]
    public function test_it_rejects_multibyte_filters_above_the_maximum_length(array $query, string $field): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?'.http_build_query($query));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_it_accepts_multibyte_filters_at_the_maximum_length(): void
    {
        $user = $this->signIn();
        $domain = str_repeat(mb_chr(0x754C), SyncFeedEntry::MAX_DOMAIN_LENGTH);
        $resourceType = str_repeat(mb_chr(0x7A2E), SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH);
        $resourceId = str_repeat(mb_chr(0x8B58), SyncFeedEntry::MAX_RESOURCE_ID_LENGTH);
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => $domain,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);

        $response = $this->getJson('/api/sync/feed?'.http_build_query([
            'domain' => $domain,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.domain', $domain)
            ->assertJsonPath('meta.resource_type', $resourceType)
            ->assertJsonPath('meta.resource_id', $resourceId);
    }

    public function test_it_rejects_array_resource_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id[]=card-1');

        $response->assertUnprocessable();
    }

    /**
     * @return array<string, array{query: array<string, string>, field: string}>
     */
    public static function multibyteOverlongFilterProvider(): array
    {
        return [
            'domain' => [
                'query' => [
                    'domain' => str_repeat(mb_chr(0x754C), SyncFeedEntry::MAX_DOMAIN_LENGTH + 1),
                ],
                'field' => 'domain',
            ],
            'resource type' => [
                'query' => [
                    'resource_type' => str_repeat(mb_chr(0x7A2E), SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH + 1),
                ],
                'field' => 'resource_type',
            ],
            'resource id' => [
                'query' => [
                    // resource_id requires valid scope companions; keep this case focused on max-length rejection.
                    'domain' => 'x',
                    'resource_type' => 'x',
                    'resource_id' => str_repeat(mb_chr(0x8B58), SyncFeedEntry::MAX_RESOURCE_ID_LENGTH + 1),
                ],
                'field' => 'resource_id',
            ],
        ];
    }
}
