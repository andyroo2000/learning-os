<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\IssueConvoLabVerificationTokenAction;
use App\Domain\Auth\Actions\RegisterConvoLabUserAction;
use App\Domain\Auth\Actions\VerifyConvoLabEmailAction;
use App\Domain\Auth\Exceptions\InvalidConvoLabVerificationTokenException;
use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Auth\Support\ConvoLabVerificationRateLimiter;
use App\Jobs\SendConvoLabVerificationEmail;
use App\Mail\ConvoLabVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabSignupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        config()->set('services.convolab.client_url', 'https://convo-lab.test');
    }

    public function test_signup_schema_tracks_account_ownership_and_hashes_verification_tokens(): void
    {
        $this->assertTrue(Schema::hasColumn('admin_user_projections', 'source_system'));
        $this->assertTrue(Schema::hasColumn('admin_invite_codes', 'source_system'));
        $this->assertTrue(Schema::hasColumns('convolab_email_verification_tokens', [
            'user_id',
            'token_hash',
            'expires_at',
            'consumed_at',
        ]));

        $projectionIndexes = collect(Schema::getIndexes('admin_user_projections'));
        $inviteIndexes = collect(Schema::getIndexes('admin_invite_codes'));
        $tokenIndexes = collect(Schema::getIndexes('convolab_email_verification_tokens'));
        $this->assertTrue($projectionIndexes->contains('name', 'admin_users_source_system_idx'));
        $this->assertTrue($inviteIndexes->contains('name', 'admin_invites_source_system_idx'));
        $this->assertTrue($tokenIndexes->where('name', 'convolab_email_verification_tokens_token_hash_unique')->first()['unique']);
    }

    public function test_signup_requires_the_named_proxy_identity_and_exact_scope(): void
    {
        $payload = $this->signupPayload();

        $this->postJson('/api/convolab/auth/signup', $payload)->assertUnauthorized();

        $ordinary = User::factory()->create()
            ->createToken('mobile', ['auth:signup'])
            ->plainTextToken;
        $this->withToken($ordinary)
            ->postJson('/api/convolab/auth/signup', $payload)
            ->assertForbidden();

        $wildcard = User::factory()->create(['email' => 'wildcard@example.com'])
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'wildcard@example.com');
        $this->withToken($wildcard)
            ->postJson('/api/convolab/auth/signup', $payload)
            ->assertForbidden();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        $this->withToken($this->proxyToken(['auth:login']))
            ->postJson('/api/convolab/auth/signup', $payload)
            ->assertForbidden();
    }

    public function test_signup_and_verification_routes_use_their_named_rate_limiters(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->keyBy(fn ($route) => implode('|', $route->methods()).' '.$route->uri());

        $this->assertContains(
            'throttle:'.AuthEmailRateLimiter::CONVOLAB_SIGNUPS,
            $routes->get('POST api/convolab/auth/signup')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ConvoLabVerificationRateLimiter::SEND,
            $routes->get('POST api/convolab/auth/verification/send')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ConvoLabVerificationRateLimiter::VERIFY,
            $routes->get('POST api/convolab/auth/verification')->gatherMiddleware(),
        );
    }

    public function test_signup_creates_a_target_owned_account_consumes_the_invite_and_queues_verification(): void
    {
        Queue::fake();
        config()->set('services.convolab.admin_emails', ['admin@example.com']);
        $inviteId = $this->invite('WELCOME1');

        $this->travelTo(Carbon::parse('2026-07-21 20:30:00.123 UTC'), function () use ($inviteId): void {
            $response = $this->withoutMiddleware(TrimStrings::class)
                ->withToken($this->proxyToken(['auth:signup']))
                ->postJson('/api/convolab/auth/signup', [
                    'email' => " ADMIN@Example.com\n",
                    'password' => 'correct horse battery staple',
                    'name' => ' Ada Lovelace ',
                    'inviteCode' => ' WELCOME1 ',
                ])
                ->assertOk();

            $convoLabId = $response->json('id');
            $this->assertTrue(Str::isUuid($convoLabId));
            $response->assertExactJson([
                'id' => $convoLabId,
                'email' => 'admin@example.com',
                'name' => 'Ada Lovelace',
                'displayName' => null,
                'avatarColor' => 'indigo',
                'role' => 'user',
                'preferredStudyLanguage' => 'ja',
                'preferredNativeLanguage' => 'en',
                'proficiencyLevel' => 'beginner',
                'onboardingCompleted' => false,
                'emailVerified' => false,
                'emailVerifiedAt' => null,
                'createdAt' => '2026-07-21T20:30:00.000Z',
                'updatedAt' => '2026-07-21T20:30:00.000Z',
            ]);

            $user = User::query()->where('convolab_id', $convoLabId)->sole();
            $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
            $this->assertTrue(password_verify(
                'correct horse battery staple',
                (string) $user->getAttribute('convolab_password_hash'),
            ));
            $this->assertArrayNotHasKey('convolab_password_hash', $user->toArray());
            $this->assertDatabaseHas('admin_user_projections', [
                'convolab_id' => $convoLabId,
                'source_system' => 'learning_os',
            ]);
            $this->assertDatabaseHas('admin_invite_codes', [
                'id' => $inviteId,
                'used_by' => $user->getKey(),
                'convolab_used_by' => $convoLabId,
                'source_system' => 'learning_os',
            ]);
            Queue::assertPushed(
                SendConvoLabVerificationEmail::class,
                fn (SendConvoLabVerificationEmail $job): bool => $job->userId === $user->getKey(),
            );
        });
    }

    public function test_signup_retry_is_idempotent_only_for_the_same_invite_and_password(): void
    {
        Queue::fake();
        $this->invite('RETRY123');
        $token = $this->proxyToken(['auth:signup']);
        $payload = $this->signupPayload(inviteCode: 'RETRY123');

        $first = $this->withToken($token)->postJson('/api/convolab/auth/signup', $payload)->assertOk();
        $second = $this->withToken($token)->postJson('/api/convolab/auth/signup', $payload)->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('admin_user_projections', 1);

        $this->withToken($token)
            ->postJson('/api/convolab/auth/signup', [...$payload, 'password' => 'wrong password'])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Invalid credentials.',
                'reason' => 'invalid_credentials',
            ]);

        $this->invite('OTHER123');
        $this->withToken($token)
            ->postJson('/api/convolab/auth/signup', [...$payload, 'inviteCode' => 'OTHER123'])
            ->assertStatus(400)
            ->assertExactJson([
                'message' => 'An account with this email already exists',
                'reason' => 'account_exists',
            ]);
    }

    public function test_signup_rejects_invalid_used_and_duplicate_account_inputs_without_side_effects(): void
    {
        Queue::fake();
        $token = $this->proxyToken(['auth:signup']);
        $payload = $this->signupPayload();

        $this->withToken($token)
            ->postJson('/api/convolab/auth/signup', $payload)
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_invite');

        $other = User::factory()->create();
        $this->invite('USED123', $other->getKey(), (string) Str::uuid());
        $this->withToken($token)
            ->postJson('/api/convolab/auth/signup', [...$payload, 'inviteCode' => 'USED123'])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'used_invite');

        User::factory()->create(['email' => 'ADA@example.com']);
        $this->invite('DUPLICATE');
        $this->withToken($token)
            ->postJson('/api/convolab/auth/signup', [...$payload, 'inviteCode' => 'DUPLICATE'])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'account_exists');

        $this->assertDatabaseCount('admin_user_projections', 0);
        Queue::assertNothingPushed();
    }

    public function test_signup_validates_bounded_scalar_input_after_request_owned_normalization(): void
    {
        $token = $this->proxyToken(['auth:signup']);

        foreach ([
            [['email' => ['ada@example.com']], 'email'],
            [['email' => 'invalid'], 'email'],
            [['password' => ['password']], 'password'],
            [['password' => 'short'], 'password'],
            [['name' => ['Ada']], 'name'],
            [['name' => str_repeat('a', 256)], 'name'],
            [['inviteCode' => ['WELCOME']], 'inviteCode'],
            [['inviteCode' => str_repeat('a', 21)], 'inviteCode'],
        ] as [$replacement, $field]) {
            $this->withoutMiddleware(TrimStrings::class)
                ->withToken($token)
                ->postJson('/api/convolab/auth/signup', [...$this->signupPayload(), ...$replacement])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_verification_job_stores_only_a_hash_and_sends_the_raw_token_in_the_link(): void
    {
        Mail::fake();
        $this->invite('VERIFY1');
        $result = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'VERIFY1',
        );

        $job = new SendConvoLabVerificationEmail((int) $result->account->user_id);
        $job->handle(app(IssueConvoLabVerificationTokenAction::class));

        $record = DB::table('convolab_email_verification_tokens')->sole();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $record->token_hash);
        Mail::assertSent(ConvoLabVerificationMail::class, function (ConvoLabVerificationMail $mail) use ($record): bool {
            $token = basename($mail->verificationUrl);

            return str_starts_with($mail->verificationUrl, 'https://convo-lab.test/verify-email/')
                && hash('sha256', $token) === $record->token_hash
                && ! str_contains($mail->verificationUrl, $record->token_hash);
        });
    }

    public function test_verification_marks_both_account_records_and_rejects_expired_or_replayed_tokens(): void
    {
        config()->set('services.convolab.admin_emails', ['ada@example.com']);
        $this->invite('VERIFY2');
        $account = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'VERIFY2',
        )->account;
        $token = app(IssueConvoLabVerificationTokenAction::class)->handle((int) $account->user_id);

        $this->withToken($this->proxyToken(['auth:verification']))
            ->postJson('/api/convolab/auth/verification', ['token' => $token])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Email verified successfully',
                'email' => 'ada@example.com',
            ]);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $account->convolab_id,
            'email_verified' => true,
            'role' => 'admin',
        ]);
        $this->assertNotNull(User::query()->findOrFail($account->user_id)->email_verified_at);
        $this->assertDatabaseHas('convolab_email_verification_tokens', [
            'user_id' => $account->user_id,
        ]);
        $this->assertNotNull(DB::table('convolab_email_verification_tokens')->sole()->consumed_at);

        $this->withToken($this->proxyToken(['auth:verification']))
            ->postJson('/api/convolab/auth/verification', ['token' => $token])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Email verified successfully',
                'email' => 'ada@example.com',
            ]);

        $this->invite('EXPIRED1');
        $unverified = app(RegisterConvoLabUserAction::class)->handle(
            'grace@example.com',
            'correct horse battery staple',
            'Grace Hopper',
            'EXPIRED1',
        )->account;
        $expired = app(IssueConvoLabVerificationTokenAction::class)->handle((int) $unverified->user_id);
        $this->assertIsString($expired);
        $expiredHash = hash('sha256', $expired);
        DB::table('convolab_email_verification_tokens')
            ->where('token_hash', $expiredHash)
            ->update(['expires_at' => now()->subSecond()]);
        try {
            app(VerifyConvoLabEmailAction::class)->handle($expired);
            $this->fail('Expected an expired verification token to be rejected.');
        } catch (InvalidConvoLabVerificationTokenException) {
            $this->assertDatabaseMissing('convolab_email_verification_tokens', [
                'token_hash' => $expiredHash,
            ]);
        }
    }

    public function test_verification_requires_a_lowercase_hex_token_in_the_request_body(): void
    {
        $token = $this->proxyToken(['auth:verification']);

        foreach ([null, ['token'], str_repeat('A', 64), str_repeat('a', 63)] as $invalidToken) {
            $payload = $invalidToken === null ? [] : ['token' => $invalidToken];

            $this->withToken($token)
                ->postJson('/api/convolab/auth/verification', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('token');
        }
    }

    public function test_verification_job_does_not_issue_or_send_after_account_verification(): void
    {
        Mail::fake();
        $this->invite('VERIFY4');
        $account = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'VERIFY4',
        )->account;
        $token = app(IssueConvoLabVerificationTokenAction::class)->handle((int) $account->user_id);
        $this->assertIsString($token);
        app(VerifyConvoLabEmailAction::class)->handle($token);

        (new SendConvoLabVerificationEmail((int) $account->user_id))
            ->handle(app(IssueConvoLabVerificationTokenAction::class));

        $this->assertDatabaseCount('convolab_email_verification_tokens', 1);
        Mail::assertNothingSent();
    }

    public function test_resend_requires_exact_scope_and_known_unverified_owner(): void
    {
        Queue::fake();
        $this->invite('VERIFY3');
        $account = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'VERIFY3',
        )->account;

        $this->withToken($this->proxyToken(['auth:read']))
            ->withHeader('X-Convo-Lab-User-Id', $account->convolab_id)
            ->postJson('/api/convolab/auth/verification/send')
            ->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->proxyToken(['auth:verification']))
            ->withHeader('X-Convo-Lab-User-Id', $account->convolab_id)
            ->postJson('/api/convolab/auth/verification/send')
            ->assertOk()
            ->assertExactJson(['message' => 'Verification email sent']);
        Queue::assertPushed(SendConvoLabVerificationEmail::class);

        DB::table('admin_user_projections')
            ->where('convolab_id', $account->convolab_id)
            ->update(['email_verified' => true]);
        $this->withToken($this->proxyToken(['auth:verification']))
            ->withHeader('X-Convo-Lab-User-Id', $account->convolab_id)
            ->postJson('/api/convolab/auth/verification/send')
            ->assertStatus(400)
            ->assertExactJson(['message' => 'Email is already verified']);

        $this->withToken($this->proxyToken(['auth:verification']))
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson('/api/convolab/auth/verification/send')
            ->assertNotFound();
    }

    /** @return array<string, string> */
    private function signupPayload(string $inviteCode = 'WELCOME1'): array
    {
        return [
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
            'name' => 'Ada Lovelace',
            'inviteCode' => $inviteCode,
        ];
    }

    private function invite(string $code, ?int $usedBy = null, ?string $convoLabUsedBy = null): string
    {
        $id = (string) Str::uuid();
        DB::table('admin_invite_codes')->insert([
            'id' => $id,
            'code' => $code,
            'used_by' => $usedBy,
            'convolab_used_by' => $convoLabUsedBy,
            'used_at' => $usedBy === null ? null : now(),
            'created_at' => now(),
            'source_system' => 'convolab',
        ]);

        return $id;
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $proxy = User::query()->where('email', 'proxy@example.com')->first()
            ?? User::factory()->create(['email' => 'proxy@example.com']);

        return $proxy->createToken('convolab-proxy', $abilities)->plainTextToken;
    }
}
