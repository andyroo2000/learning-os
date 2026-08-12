<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ReserveContentGenerationRequestAction;
use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentGenerationRequestFingerprint;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentDialogueGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecoverContentGenerationRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_resumes_a_crash_between_reservation_and_job_linkage(): void
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
            'title' => 'Recovery dialogue',
            'source_text' => 'A durable reservation survives the web process.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'draft',
            'is_sample_content' => false,
        ]);
        $data = GenerateContentDialogueData::fromInput($this->payload($episode->id));
        $clientRequestId = (string) Str::uuid();
        $reservation = app(ReserveContentGenerationRequestAction::class)->handle(
            $user->id,
            $convoLabUserId,
            $clientRequestId,
            ContentGenerationRequestState::DIALOGUE_OPERATION,
            'episode',
            $episode->id,
            ContentGenerationRequestFingerprint::dialogue($data),
            $data->toArray(),
        );
        $this->assertNull($reservation->request->job_id);

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $request = $reservation->request->fresh();
        $this->assertNotNull($request->job_id);
        $this->assertNotNull($request->dispatched_at);
        $this->assertDatabaseCount('content_dialogue_generation_jobs', 1);
        Queue::assertPushed(
            ProcessContentDialogueGeneration::class,
            fn (ProcessContentDialogueGeneration $job): bool => $job->jobId === $request->job_id
                && $job->generationRequestId === $request->id,
        );
    }

    public function test_command_reclaims_a_stale_linked_dispatch_without_creating_another_job(): void
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
            'title' => 'Recovery dialogue',
            'source_text' => 'A linked job was not acknowledged.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'draft',
            'is_sample_content' => false,
        ]);
        $clientRequestId = (string) Str::uuid();
        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => $clientRequestId,
        ])->assertOk();
        $request = ContentGenerationRequest::query()->sole();
        $request->forceFill([
            'dispatched_at' => null,
            'dispatch_token' => (string) Str::uuid(),
            'dispatch_claimed_at' => now()->subSeconds(
                ContentGenerationRequestState::DISPATCH_CLAIM_STALE_SECONDS + 1,
            ),
        ])->save();
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $this->assertNotNull($request->fresh()->dispatched_at);
        $this->assertSame(1, ContentDialogueGenerationJob::query()->count());
        Queue::assertPushed(ProcessContentDialogueGeneration::class, 1);
    }

    public function test_overlapping_recovery_scans_share_the_dispatch_claim(): void
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
            'title' => 'Overlapping recovery dialogue',
            'source_text' => 'Two schedulers see the same stale claim.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'draft',
            'is_sample_content' => false,
        ]);
        $clientRequestId = (string) Str::uuid();
        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => $clientRequestId,
        ])->assertOk();
        $request = ContentGenerationRequest::query()->sole();
        $request->forceFill([
            'dispatched_at' => null,
            'dispatch_token' => null,
            'dispatch_claimed_at' => null,
        ])->save();
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();
        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        Queue::assertPushed(ProcessContentDialogueGeneration::class, 1);
        $this->assertNotNull($request->fresh()->dispatched_at);
    }

    /** @return array<string, mixed> */
    private function payload(string $episodeId): array
    {
        return [
            'episodeId' => $episodeId,
            'speakers' => [
                ['name' => 'Aiko', 'voiceId' => 'Aiko', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => null],
                ['name' => 'Ken', 'voiceId' => 'Ken', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
            ],
            'variationCount' => 3,
            'dialogueLength' => 6,
            'jlptLevel' => 'N4',
        ];
    }
}
