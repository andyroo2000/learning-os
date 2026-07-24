<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\IssueConvoLabVerificationTokenAction;
use App\Domain\Auth\Actions\RegisterConvoLabUserAction;
use App\Domain\Auth\Actions\VerifyConvoLabEmailAction;
use App\Domain\Auth\Exceptions\InvalidConvoLabVerificationTokenException;
use App\Jobs\SendConvoLabVerificationEmail;
use App\Mail\ConvoLabVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConvoLabSignupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.client_url', 'https://convo-lab.test');
    }

    public function test_signup_schema_tracks_account_ownership_and_uses_portable_named_indexes(): void
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
        $this->assertTrue(
            $tokenIndexes
                ->firstWhere('name', 'convolab_email_verification_tokens_token_hash_unique')['unique'],
        );
    }

    public function test_retired_proxy_signup_and_verification_routes_are_not_exposed(): void
    {
        $this->postJson('/api/convolab/auth/signup', $this->signupPayload())
            ->assertNotFound();
        $this->postJson('/api/convolab/auth/verification', ['token' => str_repeat('a', 64)])
            ->assertNotFound();
    }

    public function test_signup_action_creates_a_target_owned_account_and_consumes_the_invite(): void
    {
        $inviteId = $this->invite('WELCOME1');

        $result = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'WELCOME1',
        );

        $user = User::query()->findOrFail($result->account->user_id);
        $this->assertTrue($result->created);
        $this->assertSame('ada@example.com', $result->account->email);
        $this->assertSame('Ada Lovelace', $result->account->name);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
        $this->assertTrue(Hash::check(
            'correct horse battery staple',
            (string) $user->convolab_password_hash,
        ));
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $inviteId,
            'used_by' => $user->id,
            'convolab_used_by' => $result->account->convolab_id,
        ]);
        $this->assertDatabaseHas('admin_user_projections', [
            'convolab_id' => $result->account->convolab_id,
            'source_system' => 'learning_os',
            'email_verified' => false,
        ]);
    }

    public function test_signup_retry_is_idempotent_only_for_matching_credentials_and_invite(): void
    {
        $this->invite('RETRY123');
        $action = app(RegisterConvoLabUserAction::class);

        $first = $action->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'RETRY123',
        );
        $second = $action->handle(
            'ADA@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'RETRY123',
        );

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->account->convolab_id, $second->account->convolab_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('admin_user_projections', 1);
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

        (new SendConvoLabVerificationEmail((int) $result->account->user_id))
            ->handle(app(IssueConvoLabVerificationTokenAction::class));

        $record = DB::table('convolab_email_verification_tokens')->sole();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $record->token_hash);
        Mail::assertSent(ConvoLabVerificationMail::class, function (ConvoLabVerificationMail $mail) use ($record): bool {
            $token = basename($mail->verificationUrl);

            return str_starts_with($mail->verificationUrl, 'https://convo-lab.test/verify-email/')
                && hash('sha256', $token) === $record->token_hash
                && ! str_contains($mail->verificationUrl, $record->token_hash);
        });
    }

    public function test_verification_marks_both_account_records_and_rejects_expired_tokens(): void
    {
        config()->set('services.convolab.admin_emails', ['ada@example.com']);
        $this->invite('VERIFY2');
        $account = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'VERIFY2',
        )->account;
        $issuer = app(IssueConvoLabVerificationTokenAction::class);
        $token = $issuer->handle((int) $account->user_id);

        $verified = app(VerifyConvoLabEmailAction::class)->handle($token);
        $retried = app(VerifyConvoLabEmailAction::class)->handle($token);

        $this->assertSame($account->convolab_id, $verified->convolab_id);
        $this->assertSame($verified->convolab_id, $retried->convolab_id);
        $this->assertTrue($verified->email_verified);
        $this->assertSame('admin', $verified->role);
        $this->assertNotNull(User::query()->findOrFail($account->user_id)->email_verified_at);

        $this->invite('EXPIRED1');
        $expiredAccount = app(RegisterConvoLabUserAction::class)->handle(
            'grace@example.com',
            'correct horse battery staple',
            'Grace Hopper',
            'EXPIRED1',
        )->account;
        $expired = $issuer->handle((int) $expiredAccount->user_id);
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

    public function test_verification_job_does_not_issue_or_send_after_verification(): void
    {
        Mail::fake();
        $this->invite('VERIFY4');
        $account = app(RegisterConvoLabUserAction::class)->handle(
            'ada@example.com',
            'correct horse battery staple',
            'Ada Lovelace',
            'VERIFY4',
        )->account;
        $issuer = app(IssueConvoLabVerificationTokenAction::class);
        $token = $issuer->handle((int) $account->user_id);
        app(VerifyConvoLabEmailAction::class)->handle($token);

        (new SendConvoLabVerificationEmail((int) $account->user_id))->handle($issuer);

        $this->assertDatabaseCount('convolab_email_verification_tokens', 1);
        Mail::assertNothingSent();
    }

    /** @return array<string, string> */
    private function signupPayload(): array
    {
        return [
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
            'name' => 'Ada Lovelace',
            'inviteCode' => 'WELCOME1',
        ];
    }

    private function invite(string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('admin_invite_codes')->insert([
            'id' => $id,
            'code' => $code,
            'used_by' => null,
            'convolab_used_by' => null,
            'used_at' => null,
            'created_at' => now(),
            'source_system' => 'convolab',
        ]);

        return $id;
    }
}
