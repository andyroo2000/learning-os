<?php

namespace App\Domain\Achievements\Actions;

use JsonException;
use RuntimeException;

final class GetAchievementCatalogAction
{
    public const REVISION = 'achievement-collection-v1';

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $path = public_path('achievement-assets/'.self::REVISION.'/catalog.json');

        if (! is_file($path)) {
            throw new RuntimeException("Achievement catalog is missing at {$path}.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Achievement catalog cannot be read at {$path}.");
        }

        try {
            $catalog = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Achievement catalog contains invalid JSON.', previous: $exception);
        }

        if (! is_array($catalog) || ($catalog['revision'] ?? null) !== self::REVISION) {
            throw new RuntimeException('Achievement catalog revision does not match the published asset revision.');
        }

        return $catalog;
    }
}
