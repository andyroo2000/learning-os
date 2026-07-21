<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\DeleteContentEpisodeAction;
use App\Domain\Content\Actions\UpdateContentEpisodeAction;
use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeTombstone;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ContentEpisodeWriteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_normalizes_uppercase_ids_for_direct_callers(): void
    {
        $user = User::factory()->create();
        $episode = $this->episodeFor($user);

        $updated = app(UpdateContentEpisodeAction::class)->handle(
            $user->id,
            strtoupper($episode->convolab_user_id),
            strtoupper($episode->id),
            UpdateContentEpisodeData::fromInput(['status' => 'ready']),
        );

        $this->assertTrue($updated);
        $this->assertSame('ready', $episode->fresh()->status);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $episode->fresh()->source_system);
    }

    public function test_update_rejects_malformed_ids_before_opening_a_transaction(): void
    {
        $transactionLevel = DB::transactionLevel();

        try {
            app(UpdateContentEpisodeAction::class)->handle(
                1,
                (string) Str::uuid(),
                'not-a-uuid',
                UpdateContentEpisodeData::fromInput(['status' => 'ready']),
            );
            $this->fail('Expected malformed Episode ID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Episode ID must be a UUID.', $exception->getMessage());
        }

        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_update_rejects_malformed_convolab_user_ids_before_opening_a_transaction(): void
    {
        $transactionLevel = DB::transactionLevel();

        try {
            app(UpdateContentEpisodeAction::class)->handle(
                1,
                'not-a-uuid',
                (string) Str::uuid(),
                UpdateContentEpisodeData::fromInput(['status' => 'ready']),
            );
            $this->fail('Expected malformed Convo Lab user ID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Convo Lab user ID must be a UUID.', $exception->getMessage());
        }

        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_delete_rejects_malformed_ids_before_opening_a_transaction(): void
    {
        $transactionLevel = DB::transactionLevel();

        try {
            app(DeleteContentEpisodeAction::class)->handle(1, (string) Str::uuid(), 'not-a-uuid');
            $this->fail('Expected malformed Episode ID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Episode ID must be a UUID.', $exception->getMessage());
        }

        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_delete_does_not_trust_a_conflicting_existing_tombstone(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $episode = $this->episodeFor($user);
        ContentEpisodeTombstone::query()->forceCreate([
            'episode_id' => $episode->id,
            'user_id' => $otherUser->id,
            'convolab_user_id' => (string) Str::uuid(),
            'deleted_at' => now(),
        ]);

        $deleted = app(DeleteContentEpisodeAction::class)->handle(
            $user->id,
            $episode->convolab_user_id,
            $episode->id,
        );

        $this->assertFalse($deleted);
        $this->assertDatabaseHas('content_episodes', ['id' => $episode->id]);
    }

    private function episodeFor(User $user): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Episode',
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'auto_generate_audio' => true,
            'status' => 'draft',
            'is_sample_content' => false,
            'audio_speed' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
