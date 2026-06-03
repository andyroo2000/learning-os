<?php

namespace Database\Factories;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncFeedEntry>
 */
class SyncFeedEntryFactory extends Factory
{
    protected $model = SyncFeedEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resourceId = (string) Str::ulid();

        return [
            'user_id' => User::factory(),
            'domain' => 'test_domain',
            'resource_type' => 'test_resource',
            'resource_id' => $resourceId,
            'operation' => SyncFeedOperation::Create,
            'server_recorded_at' => now(),
            'payload' => [
                'id' => $resourceId,
            ],
        ];
    }
}
