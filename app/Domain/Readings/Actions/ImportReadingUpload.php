<?php

namespace App\Domain\Readings\Actions;

use App\Domain\Readings\Data\ReadingUploadDocument;
use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ImportReadingUpload
{
    public function __construct(private readonly StoreReadingImage $images) {}

    public function handle(int $userId, array $data): Reading
    {
        $document = ReadingUploadDocument::validate($data['document'], $data['images'] ?? []);

        try {
            return DB::transaction(function () use ($userId, $data, $document): Reading {
                User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                $reading = Reading::query()->find($data['id']);
                abort_if($reading && $reading->user_id !== $userId, 404);
                $reading ??= $this->newReading($userId, $data['id']);
                $document = $this->storeImages($reading->id, $document, $data['images'] ?? []);
                $cover = isset($data['cover']) ? $this->images->pathFor($reading->id, $data['cover']->getPathname()) : $reading->illustration_path;
                if ($this->unchanged($reading, $document, $cover)) {
                    return $reading;
                }
                if ($reading->exists) {
                    abort_unless($reading->revision === (int) ($data['expectedRevision'] ?? 0), 409, 'Refresh the reading revision before replacing its content.');
                    $reading->revision++;
                }
                $reading->document = $document;
                $reading->illustration_path = $cover;
                $reading->save();
                foreach ($data['images'] ?? [] as $file) {
                    $this->images->handle($reading->id, $file->getPathname());
                }
                if (isset($data['cover'])) {
                    $this->images->handle($reading->id, $data['cover']->getPathname());
                }

                return $reading;
            });
        } catch (UniqueConstraintViolationException $error) {
            // Different owners do not share a lock; never expose a colliding identity.
            abort_if(Reading::query()->whereKey($data['id'])->where('user_id', '!=', $userId)->exists(), 404);
            throw $error;
        }
    }

    private function unchanged(Reading $reading, array $document, ?string $cover): bool
    {
        return $reading->exists && $this->sameContent($reading, $document, $cover);
    }

    private function sameContent(Reading $reading, array $document, ?string $cover): bool
    {
        // Sort object keys without coercing sentence text such as numeric strings.
        return $this->canonical($reading->document) === $this->canonical($document) && $reading->illustration_path === $cover;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($entry) => is_array($entry) ? $this->canonical($entry) : $entry, $value);
    }

    private function newReading(int $userId, string $id): Reading
    {
        $reading = new Reading;
        $reading->id = $id;
        $reading->user_id = $userId;
        $reading->slug = $id;
        $reading->revision = 1;

        return $reading;
    }

    private function storeImages(string $id, array $document, array $files): array
    {
        foreach ($document['pages'] as &$page) {
            if (isset($page['imageFile'])) {
                $page['imagePath'] = $this->images->pathFor($id, $files[$page['number']]->getPathname());
                unset($page['imageFile']);
            }
        }

        return $document;
    }
}
