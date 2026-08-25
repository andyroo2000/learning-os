<?php

namespace Tests\Support\Contracts;

use RuntimeException;

final class CompatibilityFixtureRepository
{
    public const MANIFEST_PATH = 'tests/Fixtures/Compatibility/manifest-v1.json';

    public const MANIFEST_CHECKSUM_PATH = 'tests/Fixtures/Compatibility/manifest-v1.sha256';

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        return self::decodeJson(self::MANIFEST_PATH);
    }

    /** @return array<string, mixed> */
    public static function fixture(string $id): array
    {
        $entry = collect(self::manifest()['fixtures'] ?? [])
            ->firstWhere('id', $id);

        if (! is_array($entry) || ! isset($entry['path']) || ! is_string($entry['path'])) {
            throw new RuntimeException("Compatibility fixture [{$id}] is not registered.");
        }

        return self::decodeJson($entry['path']);
    }

    /** @return array<string, mixed> */
    public static function case(string $fixtureId, string $caseId): array
    {
        $case = collect(self::fixture($fixtureId)['cases'] ?? [])
            ->firstWhere('id', $caseId);

        if (! is_array($case)) {
            throw new RuntimeException("Compatibility fixture case [{$fixtureId}:{$caseId}] is not registered.");
        }

        return $case;
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $path): array
    {
        $contents = file_get_contents(base_path($path));
        if ($contents === false) {
            throw new RuntimeException("Unable to read compatibility fixture [{$path}].");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Compatibility fixture [{$path}] must decode to an object.");
        }

        return $decoded;
    }
}
