<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendConvoLabVerificationEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RegisterConvoLabMobileUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consumes_an_invite_and_issues_a_mobile_token(): void
    {
        Queue::fake();
        $inviteId = $this->invite('MOBILE1');

        $response = $this->postJson('/api/convolab/auth/register', [
            'name' => ' Ada Lovelace ',
            'email' => ' ADA@example.com ',
            'password' => 'correct horse battery staple',
            'inviteCode' => ' MOBILE1 ',
            'device_name' => ' Ada iPhone ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Ada Lovelace')
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertNotNull($token);
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame('Ada iPhone', $token->name);
        $this->assertDatabaseHas('admin_invite_codes', [
            'id' => $inviteId,
            'used_by' => $user->id,
            'convolab_used_by' => $user->convolab_id,
        ]);
        Queue::assertPushed(
            SendConvoLabVerificationEmail::class,
            fn (SendConvoLabVerificationEmail $job): bool => $job->userId === $user->id,
        );
    }

    public function test_it_rejects_an_invalid_invite_without_creating_credentials(): void
    {
        $this->postJson('/api/convolab/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct horse battery staple',
            'inviteCode' => 'NOTVALID',
            'device_name' => 'Ada iPhone',
        ])
            ->assertBadRequest()
            ->assertJsonPath('reason', 'invalid_invite');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
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
