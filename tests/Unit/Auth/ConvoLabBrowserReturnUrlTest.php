<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Support\ConvoLabBrowserReturnUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConvoLabBrowserReturnUrlTest extends TestCase
{
    #[DataProvider('safePaths')]
    public function test_preserves_private_app_destinations(string $path): void
    {
        $this->assertSame($path, ConvoLabBrowserReturnUrl::sanitize($path));
    }

    public static function safePaths(): array
    {
        return array_map(fn ($path) => [$path], [
            '/app', '/app/', '/app/journal', '/app/journal/01abc', '/app/settings/integrations',
            '/app/journal/01abc?draft=1#correction', '/app/study?viewAs=user-123',
        ]);
    }

    #[DataProvider('unsafePaths')]
    public function test_rejects_external_auth_malformed_and_traversal_targets(mixed $value): void
    {
        $this->assertSame('/app/journal', ConvoLabBrowserReturnUrl::sanitize($value));
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($path) => [$path], [
            null, '', [], 5, 'https://evil.example', '//evil.example/app', 'javascript:alert(1)',
            '/login', '/tools', '/application', '/app/../login', '/app/%2e%2e/login',
            '/app/%2f%2fevil.example', '/app//evil', '/app\\evil', '/app?x=\\evil',
            "/app\n", "/app?x=\0", '/app?x='.str_repeat('a', 2048),
        ]);
    }
}
