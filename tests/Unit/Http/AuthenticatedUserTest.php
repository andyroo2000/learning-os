<?php

namespace Tests\Unit\Http;

use App\Http\Support\AuthenticatedUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Fluent;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticatedUserTest extends TestCase
{
    public function test_it_resolves_the_authenticated_application_user_id(): void
    {
        $request = Request::create('/api/study/overview');
        $request->setUserResolver(fn () => User::factory()->make(['id' => 42]));

        $this->assertSame(42, AuthenticatedUser::id($request));
    }

    public function test_it_accepts_positive_numeric_string_user_ids(): void
    {
        $user = new User;
        $user->setRawAttributes(['id' => '42'], sync: true);

        $request = Request::create('/api/study/overview');
        $request->setUserResolver(fn () => $user);

        $this->assertSame(42, AuthenticatedUser::id($request));
    }

    public function test_it_rejects_non_application_users(): void
    {
        $request = Request::create('/api/study/overview');
        $request->setUserResolver(fn () => new Fluent(['id' => 42]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Authenticated request user must be an application user.');

        AuthenticatedUser::id($request);
    }

    #[DataProvider('invalidUserIdProvider')]
    public function test_it_rejects_invalid_application_user_ids(int|string|null $userId): void
    {
        $user = new User;
        $user->setRawAttributes(['id' => $userId], sync: true);

        $request = Request::create('/api/study/overview');
        $request->setUserResolver(fn () => $user);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Authenticated user ID must be a positive integer.');

        AuthenticatedUser::id($request);
    }

    /**
     * @return array<string, array{int|string|null}>
     */
    public static function invalidUserIdProvider(): array
    {
        return [
            'missing' => [null],
            'zero integer' => [0],
            'zero string' => ['0'],
            'negative integer' => [-1],
            'negative string' => ['-1'],
            'decimal string' => ['1.5'],
            'prefixed string' => ['3abc'],
            'empty string' => [''],
        ];
    }
}
