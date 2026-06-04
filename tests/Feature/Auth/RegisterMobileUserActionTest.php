<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\RegisterMobileUserAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RegisterMobileUserActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_user_and_issues_a_mobile_token(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04 12:00:00'));

        $result = app(RegisterMobileUserAction::class)->handle(
            name: ' Katherine Johnson ',
            email: ' KATHERINE@example.com ',
            password: 'password123',
            deviceName: ' Katherine iPad ',
        );

        $this->assertSame('Katherine Johnson', $result->user->name);
        $this->assertSame('katherine@example.com', $result->user->email);
        $this->assertTrue(Hash::check('password123', $result->user->password));
        $this->assertNull($result->user->email_verified_at);
        $this->assertSame(now()->addMinutes((int) config('sanctum.expiration'))->toJSON(), $result->expiresAt?->toJSON());

        $token = PersonalAccessToken::findToken($result->plainTextToken);

        $this->assertNotNull($token);
        $this->assertTrue($token->tokenable->is($result->user));
        $this->assertSame('Katherine iPad', $token->name);
    }
}
