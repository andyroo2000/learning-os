<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\ManageContentGenerationQuotaAction;
use App\Domain\Content\Actions\RunQuotaLimitedContentGenerationAction;
use App\Domain\Content\Enums\ContentGenerationType;
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

    private const PROXY_EMAIL = 'quota-proxy@example.com';

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convoLabUserId = (string) Str::uuid();
        config()->set('services.convolab.proxy_user_email', self::PROXY_EMAIL);
        Carbon::setTestNow('2026-07-22T12:00:00Z');
    }

    public function test_status_requires_the_named_proxy_and_read_ability(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);

        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->getJson('/api/convolab/auth/me/quota')
            ->assertUnauthorized();

        $this->withToken($this->proxyToken(['content:write']))
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->getJson('/api/convolab/auth/me/quota')
            ->assertForbidden();

        $ordinary = User::factory()->create()
            ->createToken('mobile', ['auth:read'])
            ->plainTextToken;
        $this->withToken($ordinary)
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
            ->getJson('/api/convolab/auth/me/quota')
            ->assertForbidden();
    }

    public function test_status_validates_the_browser_user_identity(): void
    {
        $this->withToken($this->proxyToken(['auth:read']))
            ->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->getJson('/api/convolab/auth/me/quota')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
    }

    public function test_status_returns_the_existing_quota_shape_and_counts_only_the_current_utc_month(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, $this->convoLabUserId);
        $this->generationLog('2026-06-30T23:59:59.999Z', ContentGenerationType::Dialogue);
        $this->generationLog('2026-07-01T00:00:00.000Z', ContentGenerationType::Dialogue);
        $this->generationLog('2026-07-20T10:00:00.000Z', ContentGenerationType::Course);
        $this->generationLog('2026-08-01T00:00:00.000Z', ContentGenerationType::Script);

        $this->withToken($this->proxyToken(['auth:read']))
            ->withHeader('X-Convo-Lab-User-Id', strtoupper($this->convoLabUserId))
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

        $this->withToken($this->proxyToken(['auth:read']))
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
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

    public function test_runner_releases_failed_and_noop_reservations_but_keeps_the_cooldown(): void
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

    public function test_generation_errors_keep_the_legacy_metadata_and_headers(): void
    {
        config()->set('content_generation.monthly_limit', 1);
        $browserUser = User::factory()->create();
        $this->convoLabProjectionFor($browserUser, $this->convoLabUserId);
        $this->generationLog('2026-07-20T10:00:00.000Z', ContentGenerationType::Dialogue);

        $this->withToken($this->proxyToken(['content:write']))
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
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

        $this->withToken($this->proxyToken(['content:write']))
            ->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId)
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

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $proxy = User::query()->where('email', self::PROXY_EMAIL)->first()
            ?? User::factory()->create(['email' => self::PROXY_EMAIL]);

        return $proxy->createToken('convolab-proxy', $abilities)->plainTextToken;
    }
}
