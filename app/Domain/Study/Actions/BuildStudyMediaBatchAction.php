<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Models\MediaAsset;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BuildStudyMediaBatchAction
{
    public const MAX_ITEMS = 20;

    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;

    /**
     * @param  list<string>  $mediaAssetIds
     * @return list<array{id: string, mimeType: string, data: string}>
     */
    public function handle(int $userId, array $mediaAssetIds): array
    {
        if (
            $mediaAssetIds === []
            || count($mediaAssetIds) > self::MAX_ITEMS
            || collect($mediaAssetIds)->contains(fn (mixed $id): bool => ! is_string($id))
        ) {
            throw new InvalidArgumentException('Study media batches must contain between 1 and 20 IDs.');
        }

        $normalizedIds = array_map(
            static fn (string $id): string => CanonicalUlid::normalize($id),
            $mediaAssetIds,
        );

        if (
            count(array_unique($normalizedIds)) !== count($normalizedIds)
            || collect($normalizedIds)->contains(fn (string $id): bool => ! Str::isUlid($id))
        ) {
            throw new InvalidArgumentException('Study media batch IDs must be distinct ULIDs.');
        }

        $databaseIds = collect($normalizedIds)
            ->flatMap(fn (string $id): array => CanonicalUlid::databaseCandidates($id))
            ->unique()
            ->values()
            ->all();
        $assets = MediaAsset::query()
            ->where('user_id', $userId)
            ->where('disk', MediaAsset::DISK_MEDIA)
            ->whereIn('id', $databaseIds)
            ->get()
            ->keyBy(fn (MediaAsset $asset): string => CanonicalUlid::normalize($asset->id));
        $disk = Storage::disk(MediaAsset::DISK_MEDIA);
        $totalBytes = 0;
        $items = [];

        foreach ($normalizedIds as $id) {
            /** @var MediaAsset|null $asset */
            $asset = $assets->get($id);

            if ($asset === null || ! $disk->exists($asset->path)) {
                continue;
            }

            $size = $disk->size($asset->path);

            if ($size < 0 || $totalBytes + $size > self::MAX_TOTAL_BYTES) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'mimeType' => $asset->mime_type,
                'data' => base64_encode($disk->get($asset->path)),
            ];
            $totalBytes += $size;
        }

        return $items;
    }
}
