<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ClaimContentGenerationDispatchAction;
use App\Domain\Content\Actions\FailContentCourseGenerationAction;
use App\Domain\Content\Actions\FinishContentGenerationDispatchAction;
use App\Domain\Content\Actions\QueueIdempotentContentCourseGenerationAction;
use App\Domain\Content\Actions\ReserveContentGenerationRequestAction;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentCourseGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

final class ContentGenerationRequestDispatchClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_losing_a_dispatch_claim_returns_the_fresh_terminal_outcome(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $convoLabUserId = (string) Str::uuid();
        $this->asConvoLabBrowser($user, convoLabUserId: $convoLabUserId);
        $course = ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Contested dispatch',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'voice',
            'speaker1_gender' => 'female',
            'speaker2_gender' => 'male',
        ]);
        $claim = $this->mock(ClaimContentGenerationDispatchAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andReturnUsing(function (string $requestId): null {
                ContentGenerationRequest::query()->whereKey($requestId)->update([
                    'state' => ContentGenerationRequestState::FAILED,
                    'response_status' => 503,
                    'error_code' => 'queue_unavailable',
                    'error_message' => ContentCourseGeneration::QUEUE_FAILED_MESSAGE,
                    'finished_at' => now(),
                ]);

                return null;
            });
        });
        $action = new QueueIdempotentContentCourseGenerationAction(
            app(ReserveContentGenerationRequestAction::class),
            $claim,
            app(FinishContentGenerationDispatchAction::class),
            app(FailContentCourseGenerationAction::class),
        );

        $result = $action->handle(
            $user->id,
            $convoLabUserId,
            (string) Str::uuid(),
            $course->id,
        );

        $this->assertSame(ContentGenerationRequestState::FAILED, $result->request->state);
        $this->assertSame(503, $result->request->response_status);
        Queue::assertNothingPushed();
    }
}
