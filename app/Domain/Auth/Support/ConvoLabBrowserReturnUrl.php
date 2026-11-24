<?php

namespace App\Domain\Auth\Support;

use Illuminate\Http\Request;

final class ConvoLabBrowserReturnUrl
{
    public const DEFAULT_PATH = '/app/journal';

    public const SESSION_KEY = 'convolab.oauth.return_url';

    public static function sanitize(mixed $value): string
    {
        // Allow private app paths, never origins, auth endpoints or path traversal.
        if (! is_string($value) || strlen($value) > 2048) {
            return self::DEFAULT_PATH;
        }

        return preg_match('~\A/app(?:/[A-Za-z0-9_-]+)*/?(?:[?#][^\s\\\\\p{Cc}]*)?\z~u', $value) === 1
            ? $value : self::DEFAULT_PATH;
    }

    public static function remember(Request $request): void
    {
        // Invalid or omitted input replaces any target from a previous OAuth attempt.
        $request->session()->put(self::SESSION_KEY, self::sanitize($request->query('returnUrl')));
    }

    public static function consume(Request $request): string
    {
        return self::sanitize($request->session()->pull(self::SESSION_KEY));
    }

    public static function continueAt(string $path, string $returnUrl): string
    {
        $target = self::sanitize($returnUrl);
        if ($target === self::DEFAULT_PATH) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').http_build_query(['returnUrl' => $target]);
    }
}
