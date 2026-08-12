<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\PruneTerminalContentGenerationRequestsAction;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PruneTerminalContentGenerationRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_only_expired_terminal_requests_in_a_bounded_batch(): void
    {
        $now = Carbon::parse('2026-08-12 12:00:00');
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $oldCompleted = $this->request($user, $convoLabUserId, 'completed', $now->copy()->subDays(31));
        $atCutoff = $this->request($user, $convoLabUserId, 'failed', $now->copy()->subDays(30));
        $olderOutsideLimit = $this->request($user, $convoLabUserId, 'completed', $now->copy()->subDays(32));
        $recent = $this->request($user, $convoLabUserId, 'failed', $now->copy()->subDays(29));
        $pending = $this->request($user, $convoLabUserId, 'pending', $now->copy()->subDays(60));
        $active = $this->request($user, $convoLabUserId, 'active', $now->copy()->subDays(60));
        $unfinished = $this->request($user, $convoLabUserId, 'completed', null);

        $first = app(PruneTerminalContentGenerationRequestsAction::class)->handle(now: $now, limit: 2);

        $this->assertSame(2, $first->candidates);
        $this->assertSame(2, $first->deleted);
        $this->assertSame(0, $first->skipped);
        $this->assertSame(0, $first->failed);
        $this->assertDatabaseMissing('content_generation_requests', ['id' => $olderOutsideLimit->id]);
        $this->assertDatabaseMissing('content_generation_requests', ['id' => $oldCompleted->id]);
        $this->assertDatabaseHas('content_generation_requests', ['id' => $atCutoff->id]);

        $second = app(PruneTerminalContentGenerationRequestsAction::class)->handle(now: $now, limit: 500);

        $this->assertSame(1, $second->deleted);
        foreach ([$recent, $pending, $active, $unfinished] as $preserved) {
            $this->assertDatabaseHas('content_generation_requests', ['id' => $preserved->id]);
        }
    }

    public function test_dry_run_reports_eligible_requests_without_mutating_them(): void
    {
        $now = Carbon::parse('2026-08-12 12:00:00');
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $request = $this->request($user, $convoLabUserId, 'failed', $now->copy()->subDays(31));

        $this->artisan('content:prune-generation-requests', ['--dry-run' => true])
            ->expectsOutput('Dry run completed: 1 candidate(s), 0 deleted, 0 skipped, 0 failed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('content_generation_requests', ['id' => $request->id]);
    }

    public function test_prune_command_validates_its_limit(): void
    {
        $this->artisan('content:prune-generation-requests', ['--limit' => '0'])
            ->expectsOutput('The --limit option must be an integer between 1 and 5000.')
            ->assertExitCode(Command::INVALID);
        $this->artisan('content:prune-generation-requests', ['--limit' => '5001'])
            ->expectsOutput('The --limit option must be an integer between 1 and 5000.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_reusing_a_client_request_id_after_expiry_starts_a_new_generation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Replay expiry',
            'source_text' => 'Expired replay keys may begin again.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'draft',
            'is_sample_content' => false,
        ]);
        $clientRequestId = (string) Str::uuid();
        $payload = [...$this->payload($episode->id), 'clientRequestId' => $clientRequestId];

        $first = $this->postJson('/api/convolab/dialogue/generate', $payload)->assertOk();
        $oldRequest = ContentGenerationRequest::query()->sole();
        $oldRequestId = $oldRequest->id;
        $oldJobId = $first->json('jobId');
        $oldRequest->forceFill([
            'state' => ContentGenerationRequestState::COMPLETED,
            'response_status' => 200,
            'finished_at' => now()->subDays(31),
        ])->save();
        $episode->refresh()->forceFill(['status' => 'draft'])->save();

        app(PruneTerminalContentGenerationRequestsAction::class)->handle();
        $this->assertSame('draft', $episode->fresh()->status);
        $second = $this->postJson('/api/convolab/dialogue/generate', $payload)
            ->assertOk()
            ->assertJsonPath('clientRequestId', $clientRequestId);

        $this->assertNotSame($oldJobId, $second->json('jobId'));
        $this->assertDatabaseMissing('content_generation_requests', ['id' => $oldRequestId]);
        $this->assertDatabaseHas('content_generation_requests', [
            'client_request_id' => $clientRequestId,
            'job_id' => $second->json('jobId'),
        ]);
    }

    public function test_prune_schedule_is_daily_single_server_and_overlap_locked(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'content:prune-generation-requests'));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }

    private function request(
        User $user,
        string $convoLabUserId,
        string $state,
        ?Carbon $finishedAt,
    ): ContentGenerationRequest {
        return ContentGenerationRequest::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'client_request_id' => (string) Str::uuid(),
            'operation' => ContentGenerationRequestState::DIALOGUE_OPERATION,
            'resource_type' => 'episode',
            'resource_id' => (string) Str::uuid(),
            'input_fingerprint' => hash('sha256', (string) Str::uuid()),
            'input_payload' => [],
            'state' => $state,
            'finished_at' => $finishedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $episodeId): array
    {
        return [
            'episodeId' => $episodeId,
            'speakers' => [
                ['name' => 'Aiko', 'voiceId' => 'ja-JP-Neural2-B', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => '#112233'],
                ['name' => 'Ken', 'voiceId' => 'Ken', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
            ],
            'variationCount' => 3,
            'dialogueLength' => 6,
            'jlptLevel' => 'N4',
        ];
    }
}
