<?php

namespace Tests\Feature\Content;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentGenerationQuotaRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_quota_storage_and_api_are_removed(): void
    {
        $this->assertFalse(Schema::hasTable('generation_logs'));
        $this->assertFalse(Schema::hasTable('content_generation_cooldowns'));

        $this->actingAs(User::factory()->create())
            ->getJson('/api/convolab/auth/me/quota')
            ->assertNotFound();
    }
}
