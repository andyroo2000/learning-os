<?php

namespace App\Support\Rehearsal;

use App\Domain\Media\Models\MediaAsset;
use Closure;
use Illuminate\Database\ConnectionInterface;

class ConvoLabMediaImporter
{
    /**
     * @param  Closure(string): int  $mappedUserId
     * @param  Closure(?string): ?string  $mappedImportJobId
     * @param  Closure(): string  $newCanonicalUlid
     * @param  Closure(mixed, string): string  $stringOrDefault
     */
    public function __construct(
        private readonly Closure $mappedUserId,
        private readonly Closure $mappedImportJobId,
        private readonly Closure $newCanonicalUlid,
        private readonly Closure $stringOrDefault,
    ) {}

    /**
     * @return array{
     *     media_ids: array<string, string>,
     *     media_user_ids: array<string, int>,
     *     imported: int,
     *     deduplicated: int
     * }
     */
    public function import(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $mediaIds = [];
        $mediaUserIds = [];
        $mediaPathIds = [];
        $mediaPathUserIds = [];
        $count = 0;
        $deduped = 0;

        $source->table('study_media')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(500, function ($mediaRows) use (
                $target,
                &$mediaIds,
                &$mediaUserIds,
                &$mediaPathIds,
                &$mediaPathUserIds,
                &$count,
                &$deduped,
            ): void {
                $insertRows = [];

                foreach ($mediaRows as $media) {
                    $path = ($this->stringOrDefault)($media->storagePath, 'convolab-missing/'.$media->id);
                    $pathKey = MediaAsset::DISK_MEDIA."\n".$path;
                    $userId = ($this->mappedUserId)($media->userId);
                    $mediaUserIds[$media->id] = $userId;

                    if (isset($mediaPathIds[$pathKey])) {
                        if ($mediaPathUserIds[$pathKey] !== $userId) {
                            throw new \RuntimeException("Media path [{$path}] is shared by multiple Convo Lab users.");
                        }

                        $mediaIds[$media->id] = $mediaPathIds[$pathKey];
                        $deduped++;

                        continue;
                    }

                    $id = ($this->newCanonicalUlid)();
                    $mediaIds[$media->id] = $id;
                    $mediaPathIds[$pathKey] = $id;
                    $mediaPathUserIds[$pathKey] = $userId;

                    $insertRows[] = [
                        'id' => $id,
                        'user_id' => $userId,
                        'import_job_id' => ($this->mappedImportJobId)($media->importJobId),
                        'disk' => MediaAsset::DISK_MEDIA,
                        'path' => $path,
                        'public_url' => $media->publicUrl,
                        'mime_type' => ($this->stringOrDefault)($media->contentType, 'application/octet-stream'),
                        // The rehearsal imports metadata only; media bytes are copied in a later rollout step.
                        'size_bytes' => 0,
                        'checksum_sha256' => null,
                        'original_filename' => $media->sourceFilename,
                        'source_kind' => $media->sourceKind,
                        'source_media_ref' => $media->sourceMediaKey,
                        'source_filename' => $media->sourceFilename,
                        'created_at' => $media->createdAt,
                        'updated_at' => $media->updatedAt,
                    ];
                }

                if ($insertRows !== []) {
                    $target->table('media_assets')->insert($insertRows);
                }

                $count += count($insertRows);
            });

        return [
            'media_ids' => $mediaIds,
            'media_user_ids' => $mediaUserIds,
            'imported' => $count,
            'deduplicated' => $deduped,
        ];
    }
}
