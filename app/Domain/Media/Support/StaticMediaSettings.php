<?php

namespace App\Domain\Media\Support;

final class StaticMediaSettings
{
    public const DEFAULT_TTL_SECONDS = 12 * 60 * 60;

    public const MIN_TTL_SECONDS = 5 * 60;

    public const MAX_TTL_SECONDS = 24 * 60 * 60;

    public const DEFAULT_RATE_LIMIT_WINDOW_MS = 60 * 1000;

    public const MIN_RATE_LIMIT_WINDOW_MS = 1000;

    public const MAX_RATE_LIMIT_WINDOW_MS = 60 * 60 * 1000;

    public const DEFAULT_RATE_LIMIT_MAX_REQUESTS = 120;

    public const MIN_RATE_LIMIT_MAX_REQUESTS = 1;

    public const MAX_RATE_LIMIT_MAX_REQUESTS = 5000;

    public function bucketName(): ?string
    {
        $bucket = config('static_media.gcs.bucket');

        return is_string($bucket) && trim($bucket) !== '' ? trim($bucket) : null;
    }

    public function avatarSigningEnabled(): bool
    {
        return $this->signingEnabled(config('static_media.avatars.signed_urls_enabled'));
    }

    public function toolAudioSigningEnabled(): bool
    {
        return $this->signingEnabled(config('static_media.tool_audio.signed_urls_enabled'));
    }

    public function avatarTtlSeconds(): int
    {
        return $this->boundedInteger(
            config('static_media.avatars.signed_url_ttl_seconds'),
            self::DEFAULT_TTL_SECONDS,
            self::MIN_TTL_SECONDS,
            self::MAX_TTL_SECONDS,
        );
    }

    public function toolAudioTtlSeconds(): int
    {
        return $this->boundedInteger(
            config('static_media.tool_audio.signed_url_ttl_seconds'),
            self::DEFAULT_TTL_SECONDS,
            self::MIN_TTL_SECONDS,
            self::MAX_TTL_SECONDS,
        );
    }

    public function toolAudioRateLimitWindowSeconds(): int
    {
        $milliseconds = $this->boundedInteger(
            config('static_media.tool_audio.rate_limit_window_ms'),
            self::DEFAULT_RATE_LIMIT_WINDOW_MS,
            self::MIN_RATE_LIMIT_WINDOW_MS,
            self::MAX_RATE_LIMIT_WINDOW_MS,
        );

        return (int) ceil($milliseconds / 1000);
    }

    public function toolAudioRateLimitMaxRequests(): int
    {
        return $this->boundedInteger(
            config('static_media.tool_audio.rate_limit_max_requests'),
            self::DEFAULT_RATE_LIMIT_MAX_REQUESTS,
            self::MIN_RATE_LIMIT_MAX_REQUESTS,
            self::MAX_RATE_LIMIT_MAX_REQUESTS,
        );
    }

    public function avatarObjectPath(string $avatarPath): string
    {
        return $this->rootedPath(
            config('static_media.avatars.gcs_root'),
            'avatars',
            $avatarPath,
        );
    }

    public function toolAudioObjectPath(string $requestPath): string
    {
        return $this->rootedPath(
            config('static_media.tool_audio.gcs_root'),
            'tools-audio',
            preg_replace('#^/tools-audio/#', '', $requestPath) ?? $requestPath,
        );
    }

    public function publicObjectUrl(string $objectPath): ?string
    {
        $bucket = $this->bucketName();
        if ($bucket === null) {
            return null;
        }

        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $objectPath)));

        return "https://storage.googleapis.com/{$bucket}/{$encodedPath}";
    }

    public function publicObjectPath(string $url): ?string
    {
        $bucket = $this->bucketName();
        $parts = parse_url($url);
        if ($bucket === null || $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'storage.googleapis.com'
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $encodedPrefix = '/'.rawurlencode($bucket).'/';
        $path = $parts['path'] ?? '';
        if (! str_starts_with($path, $encodedPrefix)) {
            return null;
        }

        $encodedSegments = explode('/', substr($path, strlen($encodedPrefix)));
        $segments = array_map(rawurldecode(...), $encodedSegments);
        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return null;
        }
        foreach ($segments as $segment) {
            if (str_contains($segment, '/') || str_contains($segment, '\\')) {
                return null;
            }
        }

        $objectPath = implode('/', $segments);
        $avatarRoot = rtrim($this->avatarObjectPath(''), '/').'/';

        return str_starts_with($objectPath, $avatarRoot) ? $objectPath : null;
    }

    private function signingEnabled(mixed $configured): bool
    {
        if (is_bool($configured)) {
            return $configured;
        }

        if (is_string($configured)) {
            $parsed = filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Preserve Convo Lab's convention: a shared bucket enables both static-media signers.
        return $this->bucketName() !== null;
    }

    private function boundedInteger(mixed $value, int $fallback, int $minimum, int $maximum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            return $fallback;
        }

        return min(max($parsed, $minimum), $maximum);
    }

    private function rootedPath(mixed $configuredRoot, string $fallbackRoot, string $relativePath): string
    {
        $root = is_string($configuredRoot) ? trim($configuredRoot, '/') : '';
        if ($root === '') {
            $root = $fallbackRoot;
        }

        return $root.'/'.ltrim($relativePath, '/');
    }
}
