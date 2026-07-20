<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Support\StaticMediaPath;
use App\Domain\Media\Support\StaticMediaSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ResolveToolAudioUrlsAction
{
    public function __construct(
        private readonly StaticMediaObjectStore $objectStore,
        private readonly StaticMediaSettings $settings,
    ) {}

    /**
     * @param  list<string>  $paths
     * @return array{
     *     mode: 'passthrough'|'signed',
     *     ttlSeconds: int,
     *     urls: array<string, array{url: string, expiresAt: string}>
     * }
     */
    public function handle(array $paths): array
    {
        $this->assertValidPaths($paths);

        $ttlSeconds = $this->settings->toolAudioTtlSeconds();
        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);
        $fallbackExpiresAt = $expiresAt->toISOString();

        if (! $this->settings->toolAudioSigningEnabled()) {
            return [
                'mode' => 'passthrough',
                'ttlSeconds' => $ttlSeconds,
                'urls' => $this->passthroughUrls($paths, $fallbackExpiresAt),
            ];
        }

        $urls = [];
        foreach ($paths as $path) {
            try {
                $objectPath = $this->settings->toolAudioObjectPath($path);
                $url = $this->objectStore->exists($objectPath)
                    ? $this->objectStore->signedReadUrl($objectPath, $expiresAt, null)
                    : $path;
            } catch (Throwable $exception) {
                Log::warning('Tool audio signed URL resolution fell back to passthrough.', [
                    'request_path' => $path,
                    'exception' => $exception,
                ]);
                $url = $path;
            }

            $urls[$path] = [
                'url' => $url,
                'expiresAt' => $fallbackExpiresAt,
            ];
        }

        return [
            'mode' => 'signed',
            'ttlSeconds' => $ttlSeconds,
            'urls' => $urls,
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function assertValidPaths(array $paths): void
    {
        if (
            $paths === []
            || count($paths) > StaticMediaPath::MAX_TOOL_AUDIO_PATHS
            || ! $this->allPathsAreValid($paths)
        ) {
            throw new InvalidArgumentException('Tool audio paths must be normalized and allowlisted.');
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function allPathsAreValid(array $paths): bool
    {
        foreach ($paths as $path) {
            if (! is_string($path) || ! StaticMediaPath::isToolAudio($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{url: string, expiresAt: string}>
     */
    private function passthroughUrls(array $paths, string $expiresAt): array
    {
        $urls = [];
        foreach ($paths as $path) {
            $urls[$path] = ['url' => $path, 'expiresAt' => $expiresAt];
        }

        return $urls;
    }
}
