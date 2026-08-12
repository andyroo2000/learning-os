<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ReserveContentGenerationRequestAction;
use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Models\ContentCourse;
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

    public function test_command_reconciles_terminal_dialogue_job_and_course_states_without_redispatching(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $episode = $this->episode($user, $convoLabUserId);
        $course = $this->course($user, $convoLabUserId);

        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $this->postJson("/api/convolab/courses/{$course->id}/generate", [
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $requests = ContentGenerationRequest::query()->orderBy('operation')->get()->keyBy('operation');
        $dialogueRequest = $requests->get(ContentGenerationRequestState::DIALOGUE_OPERATION);
        $courseRequest = $requests->get(ContentGenerationRequestState::COURSE_OPERATION);
        ContentDialogueGenerationJob::query()->whereKey($dialogueRequest->job_id)->update([
            'state' => 'completed',
            'finished_at' => now(),
        ]);
        $course->forceFill(['status' => 'error', 'generation_error_message' => 'Provider failed.'])->save();
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $this->assertSame('completed', $dialogueRequest->fresh()->state);
        $this->assertSame(200, $dialogueRequest->fresh()->response_status);
        $this->assertSame([], $dialogueRequest->fresh()->input_payload);
        $this->assertSame('failed', $courseRequest->fresh()->state);
        $this->assertSame('Provider failed.', $courseRequest->fresh()->error_message);
        Queue::assertNothingPushed();
    }

    public function test_command_fails_superseded_and_deleted_generation_resources(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $episode = $this->episode($user, $convoLabUserId);
        $course = $this->course($user, $convoLabUserId);

        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $this->postJson("/api/convolab/courses/{$course->id}/generate", [
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $requests = ContentGenerationRequest::query()->orderBy('operation')->get()->keyBy('operation');
        $dialogueRequest = $requests->get(ContentGenerationRequestState::DIALOGUE_OPERATION);
        $courseRequest = $requests->get(ContentGenerationRequestState::COURSE_OPERATION);
        $episode->dialogue_generation_attempt = 2;
        $episode->save();
        $course->delete();
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $this->assertSame('generation_superseded', $dialogueRequest->fresh()->error_code);
        $this->assertSame('generation_resource_missing', $courseRequest->fresh()->error_code);
        Queue::assertNothingPushed();
    }

    public function test_command_does_not_complete_a_terminal_job_for_a_superseded_dialogue_attempt(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $episode = $this->episode($user, $convoLabUserId);

        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $request = ContentGenerationRequest::query()->sole();
        ContentDialogueGenerationJob::query()->whereKey($request->job_id)->update([
            'state' => 'completed',
            'finished_at' => now(),
        ]);
        $episode->dialogue_generation_attempt = 2;
        $episode->save();
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $this->assertSame('failed', $request->fresh()->state);
        $this->assertSame('generation_superseded', $request->fresh()->error_code);
        Queue::assertNothingPushed();
    }

    public function test_command_fails_a_dialogue_request_after_its_episode_is_deleted(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $episode = $this->episode($user, $convoLabUserId);

        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $request = ContentGenerationRequest::query()->sole();
        $episode->delete();
        $this->assertDatabaseMissing('content_dialogue_generation_jobs', ['id' => $request->job_id]);
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $this->assertSame('failed', $request->fresh()->state);
        $this->assertSame('generation_resource_missing', $request->fresh()->error_code);
        Queue::assertNothingPushed();
    }

    public function test_command_leaves_active_in_flight_generation_requests_untouched(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $episode = $this->episode($user, $convoLabUserId);

        $this->postJson('/api/convolab/dialogue/generate', [
            ...$this->payload($episode->id),
            'clientRequestId' => (string) Str::uuid(),
        ])->assertOk();
        $request = ContentGenerationRequest::query()->sole();
        $request->state = 'active';
        $request->save();
        ContentDialogueGenerationJob::query()->whereKey($request->job_id)->update([
            'state' => 'active',
            'started_at' => now(),
        ]);
        Queue::fake();

        $this->artisan('content:recover-generation-requests')->assertSuccessful();

        $this->assertSame('active', $request->fresh()->state);
        $this->assertNull($request->fresh()->finished_at);
        Queue::assertNothingPushed();
    }

    private function episode(User $user, string $convoLabUserId): ContentEpisode
    {
        return ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Recovery dialogue',
            'source_text' => 'Recover this generation.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'dialogue',
            'status' => 'draft',
            'is_sample_content' => false,
        ]);
    }

    private function course(User $user, string $convoLabUserId): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Recovery course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'fishaudio:ac934b39586e475b83f3277cd97b5cd4',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
        ]);
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
