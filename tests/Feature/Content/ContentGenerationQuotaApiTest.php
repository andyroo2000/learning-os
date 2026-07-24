<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ManageContentGenerationQuotaAction;
use App\Domain\Content\Actions\RunQuotaLimitedContentGenerationAction;
use App\Domain\Content\Enums\ContentGenerationType;
use App\Domain\Content\Exceptions\ContentDialogueGenerationConflictException;
use App\Domain\Content\Exceptions\ContentGenerationCooldownException;
use App\Domain\Content\Exceptions\ContentGenerationQuotaExceededException;
use App\Domain\Content\Models\ContentGenerationCooldown;
use App\Domain\Content\Models\ContentGenerationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ContentGenerationQuotaApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convoLabUserId = (string) Str::uuid();
        Carbon::setTestNow('2026-07-22T12:00:00Z');
    }

    public function test_status_requires_a_first_party_browser_session(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);

        $this->getJson('/api/convolab/auth/me/quota')
            ->assertUnauthorized();

        $token = $user->createToken('mobile', ['auth:read'])->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/convolab/auth/me/quota')
            ->assertForbidden();
    }

    public function test_status_ignores_spoofed_identity_headers(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);

        $this->asConvoLabBrowser($user, convoLabUserId: $this->convoLabUserId)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->getJson('/api/convolab/auth/me/quota')
            ->assertOk();
    }

    public function test_status_returns_the_existing_quota_shape_and_counts_only_the_current_utc_month(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);
        $this->generationLog('2026-06-30T23:59:59.999Z', ContentGenerationType::Dialogue);
        $this->generationLog('2026-07-01T00:00:00.000Z', ContentGenerationType::Dialogue);
        $this->generationLog('2026-07-20T10:00:00.000Z', ContentGenerationType::Course);
        $this->generationLog('2026-08-01T00:00:00.000Z', ContentGenerationType::Script);

        $this->asConvoLabBrowser($user, convoLabUserId: strtoupper($this->convoLabUserId))
            ->getJson('/api/convolab/auth/me/quota')
            ->assertExactJson([
                'unlimited' => false,
                'quota' => [
                    'used' => 2,
                    'limit' => 30,
                    'remaining' => 28,
                    'resetsAt' => '2026-08-01T00:00:00.000Z',
                ],
                'cooldown' => [
                    'active' => false,
                    'remainingSeconds' => 0,
                ],
            ]);
    }

    public function test_admins_are_unlimited_and_do_not_create_policy_records(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId, ['role' => 'admin']);

        $result = app(RunQuotaLimitedContentGenerationAction::class)->handle(
            $this->convoLabUserId,
            ContentGenerationType::Dialogue,
            null,
            fn (): object => (object) ['id' => 'generated'],
            fn (object $generated): string => $generated->id,
        );

        $this->assertSame('generated', $result->id);
        $this->assertDatabaseCount('generation_logs', 0);
        $this->assertDatabaseCount('content_generation_cooldowns', 0);

        $this->asConvoLabBrowser($user, role: 'admin', convoLabUserId: $this->convoLabUserId)
            ->getJson('/api/convolab/auth/me/quota')
            ->assertExactJson([
                'unlimited' => true,
                'quota' => null,
                'cooldown' => ['active' => false, 'remainingSeconds' => 0],
            ]);
    }

    public function test_reservations_enforce_the_shared_limit_and_cooldown_across_content_types(): void
    {
        config()->set('content_generation.monthly_limit', 2);
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);
        $quota = app(ManageContentGenerationQuotaAction::class);

        $dialogue = $quota->reserve(
            $this->convoLabUserId,
            ContentGenerationType::Dialogue,
            'dialogue-id',
        );
        $this->assertNotNull($dialogue);

        try {
            $quota->reserve($this->convoLabUserId, ContentGenerationType::Script);
            $this->fail('An active cooldown should reject a second reservation.');
        } catch (ContentGenerationCooldownException $exception) {
            $this->assertSame(30, $exception->remainingSeconds());
        }

        $this->travel(31)->seconds();
        $course = $quota->reserve($this->convoLabUserId, ContentGenerationType::Course);
        $this->assertNotNull($course);

        $this->travel(31)->seconds();
        try {
            $quota->reserve($this->convoLabUserId, ContentGenerationType::Script);
            $this->fail('The shared monthly quota should reject a third reservation.');
        } catch (ContentGenerationQuotaExceededException $exception) {
            $this->assertSame([
                'used' => 2,
                'limit' => 2,
                'remaining' => 0,
                'resetsAt' => '2026-08-01T00:00:00.000Z',
            ], $exception->quota());
        }

        $this->assertDatabaseCount('generation_logs', 2);
        $this->assertDatabaseHas('generation_logs', [
            'userId' => $this->convoLabUserId,
            'contentType' => 'dialogue',
            'contentId' => 'dialogue-id',
        ]);
        $this->assertDatabaseHas('generation_logs', [
            'userId' => $this->convoLabUserId,
            'contentType' => 'course',
        ]);
    }

    public function test_runner_keeps_failed_attempt_cooldown_but_clears_noop_reservation_and_cooldown(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);
        $runner = app(RunQuotaLimitedContentGenerationAction::class);

        try {
            $runner->handle(
                $this->convoLabUserId,
                ContentGenerationType::Dialogue,
                null,
                fn (): never => throw new RuntimeException('Queue unavailable.'),
                fn (): string => 'unused',
            );
            $this->fail('The operation exception should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseCount('generation_logs', 0);
        $this->assertDatabaseHas('content_generation_cooldowns', [
            'convolab_user_id' => $this->convoLabUserId,
        ]);

        $this->travel(31)->seconds();
        $this->assertNull($runner->handle(
            $this->convoLabUserId,
            ContentGenerationType::Course,
            null,
            fn (): null => null,
            fn (): string => 'unused',
        ));
        $this->assertDatabaseCount('generation_logs', 0);
        $this->assertDatabaseCount('content_generation_cooldowns', 0);

        $this->travel(31)->seconds();
        $generated = $runner->handle(
            $this->convoLabUserId,
            ContentGenerationType::Script,
            null,
            fn (): object => (object) ['id' => 'script-id'],
            fn (object $result): string => $result->id,
        );
        $this->assertSame('script-id', $generated->id);
        $this->assertDatabaseHas('generation_logs', [
            'contentType' => 'script',
            'contentId' => 'script-id',
        ]);
    }

    public function test_rejected_generation_clears_only_its_own_cooldown(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);
        $runner = app(RunQuotaLimitedContentGenerationAction::class);

        try {
            $runner->handle(
                $this->convoLabUserId,
                ContentGenerationType::Dialogue,
                null,
                fn (): never => throw ContentDialogueGenerationConflictException::alreadyGenerating(),
                fn (): string => 'unused',
            );
            $this->fail('The domain rejection should be rethrown.');
        } catch (ContentDialogueGenerationConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('generation_logs', 0);
        $this->assertDatabaseCount('content_generation_cooldowns', 0);

        $reservation = app(ManageContentGenerationQuotaAction::class)->reserve(
            $this->convoLabUserId,
            ContentGenerationType::Course,
        );
        $this->assertNotNull($reservation);
        $newerReservationId = (string) Str::uuid();
        DB::table('content_generation_cooldowns')
            ->where('convolab_user_id', $this->convoLabUserId)
            ->update(['generation_log_id' => $newerReservationId]);

        app(ManageContentGenerationQuotaAction::class)->cancel(
            $reservation,
            clearCooldown: true,
        );

        $this->assertDatabaseHas('content_generation_cooldowns', [
            'convolab_user_id' => $this->convoLabUserId,
            'generation_log_id' => $newerReservationId,
        ]);
    }

    public function test_generation_errors_keep_the_legacy_metadata_and_headers(): void
    {
        config()->set('content_generation.monthly_limit', 1);
        $browserUser = User::factory()->create();
        $this->convoLabProjectionFor($browserUser, $this->convoLabUserId);
        $this->generationLog('2026-07-20T10:00:00.000Z', ContentGenerationType::Dialogue);

        $this->asConvoLabBrowser($browserUser, convoLabUserId: $this->convoLabUserId)
            ->postJson('/api/convolab/scripts', ['sourceText' => '日本語です。'])
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset', '2026-08-01T00:00:00.000Z')
            ->assertExactJson([
                'message' => "Quota exceeded. You've used 1 of 1 content generations.",
                'quota' => [
                    'used' => 1,
                    'limit' => 1,
                    'remaining' => 0,
                    'resetsAt' => '2026-08-01T00:00:00.000Z',
                ],
            ]);
        $this->assertDatabaseCount('content_episodes', 0);

        ContentGenerationLog::query()->delete();
        $cooldown = new ContentGenerationCooldown;
        $cooldown->convolab_user_id = $this->convoLabUserId;
        $cooldown->available_at = now()->addSeconds(12);
        $cooldown->save();

        $this->asConvoLabBrowser($browserUser, convoLabUserId: $this->convoLabUserId)
            ->postJson('/api/convolab/scripts', ['sourceText' => '日本語です。'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '12')
            ->assertExactJson([
                'message' => 'Please wait 12 seconds before generating more content.',
                'cooldown' => [
                    'remainingSeconds' => 12,
                    'retryAfter' => '2026-07-22T12:00:12.000Z',
                ],
            ]);
        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_policy_metadata_cannot_be_mass_assigned(): void
    {
        foreach ([
            [ContentGenerationLog::class, 'contentType'],
            [ContentGenerationLog::class, 'createdAt'],
            [ContentGenerationCooldown::class, 'available_at'],
            [ContentGenerationCooldown::class, 'generation_log_id'],
        ] as [$model, $field]) {
            try {
                (new $model)->fill([$field => 'untrusted']);
                $this->fail("Expected {$model}::{$field} to reject mass assignment.");
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function generationLog(
        string $createdAt,
        ContentGenerationType $type,
    ): void {
        DB::table('generation_logs')->insert([
            'id' => (string) Str::uuid(),
            'userId' => $this->convoLabUserId,
            'contentType' => $type->value,
            'contentId' => null,
            'createdAt' => $createdAt,
        ]);
    }
}
