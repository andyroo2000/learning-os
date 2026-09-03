<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final class SyncConvoLabSpeakerAvatarsAction
{
    public function handle(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;
        $sourceIds = [];
        $seenFilenames = [];

        $source->table('SpeakerAvatar')
            ->chunkById(200, function ($avatars) use ($target, &$count, &$sourceIds, &$seenFilenames): void {
                $normalizedAvatars = $this->normalizeAvatars($avatars, $seenFilenames);
                $count += $this->syncChunk($target, $normalizedAvatars, $sourceIds);
            }, 'id');

        $target->table('admin_speaker_avatars')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->when($sourceIds !== [], fn ($query) => $query->whereNotIn('id', $sourceIds))
            ->delete();

        return $count;
    }

    /**
     * @param  iterable<int, stdClass>  $avatars
     * @param  array<string, true>  $seenFilenames
     * @return list<array{stdClass, string, string, string, string, string, string, string}>
     */
    private function normalizeAvatars(iterable $avatars, array &$seenFilenames): array
    {
        $normalized = [];

        foreach ($avatars as $avatar) {
            $normalized[] = $this->normalizeAvatar($avatar, $seenFilenames);
        }

        return $normalized;
    }

    /**
     * @param  array<string, true>  $seenFilenames
     * @return array{stdClass, string, string, string, string, string, string, string}
     */
    private function normalizeAvatar(stdClass $avatar, array &$seenFilenames): array
    {
        $id = strtolower(trim((string) $avatar->id));
        if (! Str::isUuid($id)) {
            throw new RuntimeException("Convo Lab speaker avatar [{$avatar->id}] has an invalid UUID.");
        }

        $filename = strtolower($this->sourceRequiredString($avatar, 'filename', 255));
        $this->guardFilename($id, $filename, $seenFilenames);

        $language = strtolower($this->sourceRequiredString($avatar, 'language', 16));
        $gender = strtolower($this->sourceRequiredString($avatar, 'gender', 16));
        $tone = strtolower($this->sourceRequiredString($avatar, 'tone', 16));
        $this->guardVoiceMetadata($id, $language, $gender, $tone);

        return [
            $avatar,
            $id,
            $filename,
            $language,
            $gender,
            $tone,
            $this->sourceRequiredString($avatar, 'croppedUrl', 2048),
            $this->sourceRequiredString($avatar, 'originalUrl', 2048),
        ];
    }

    /** @param array<string, true> $seenFilenames */
    private function guardFilename(string $id, string $filename, array &$seenFilenames): void
    {
        if (preg_match('/^ja-(male|female)-(casual|polite|formal)\.(jpg|jpeg|png|webp)$/', $filename) !== 1) {
            throw new RuntimeException("Convo Lab speaker avatar [{$id}] has an invalid filename.");
        }

        if (isset($seenFilenames[$filename])) {
            throw new RuntimeException('Convo Lab speaker avatars must have unique filenames.');
        }

        $seenFilenames[$filename] = true;
    }

    private function guardVoiceMetadata(string $id, string $language, string $gender, string $tone): void
    {
        if ($language !== 'ja') {
            $this->throwInvalidVoiceMetadata($id);
        }
        if (! in_array($gender, ['male', 'female'], true)) {
            $this->throwInvalidVoiceMetadata($id);
        }
        if (! in_array($tone, ['casual', 'polite', 'formal'], true)) {
            $this->throwInvalidVoiceMetadata($id);
        }
    }

    private function throwInvalidVoiceMetadata(string $id): never
    {
        throw new RuntimeException("Convo Lab speaker avatar [{$id}] has invalid voice metadata.");
    }

    /**
     * @param  list<array{stdClass, string, string, string, string, string, string, string}>  $avatars
     * @param  list<string>  $sourceIds
     */
    private function syncChunk(ConnectionInterface $target, array $avatars, array &$sourceIds): int
    {
        [$ownedIds, $ownedFilenames] = $this->learningOsOwnership($target, $avatars);
        $this->deleteRotatedSourceAvatars($target, $avatars);
        $count = 0;

        foreach ($avatars as $avatar) {
            $id = $avatar[1];
            if (! $ownedIds->has($id) && ! $ownedFilenames->has($avatar[2])) {
                $this->upsertAvatar($target, $avatar);
            }

            $sourceIds[] = $id;
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array{stdClass, string, string, string, string, string, string, string}>  $avatars
     * @return array{Collection<string, true>, Collection<string, true>}
     */
    private function learningOsOwnership(ConnectionInterface $target, array $avatars): array
    {
        $learningOsOwned = $target->table('admin_speaker_avatars')
            ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
            ->where(function ($query) use ($avatars): void {
                $query->whereIn('id', array_column($avatars, 1))
                    ->orWhereIn('filename', array_column($avatars, 2));
            })
            ->get(['id', 'filename']);

        return [
            $learningOsOwned->pluck('id')->mapWithKeys(
                static fn (string $id): array => [strtolower($id) => true],
            ),
            $learningOsOwned->pluck('filename')->mapWithKeys(
                static fn (string $filename): array => [strtolower($filename) => true],
            ),
        ];
    }

    /** @param list<array{stdClass, string, string, string, string, string, string, string}> $avatars */
    private function deleteRotatedSourceAvatars(ConnectionInterface $target, array $avatars): void
    {
        $sourceIdByFilename = collect($avatars)->mapWithKeys(
            static fn (array $avatar): array => [$avatar[2] => $avatar[1]],
        );
        $rotatedSourceIds = $target->table('admin_speaker_avatars')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->whereIn('filename', array_column($avatars, 2))
            ->get(['id', 'filename'])
            ->filter(static fn (stdClass $avatar): bool => strtolower((string) $avatar->id)
                !== $sourceIdByFilename->get(strtolower((string) $avatar->filename)))
            ->pluck('id');

        if ($rotatedSourceIds->isEmpty()) {
            return;
        }

        $target->table('admin_speaker_avatars')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->whereIn('id', $rotatedSourceIds)
            ->delete();
    }

    /** @param array{stdClass, string, string, string, string, string, string, string} $avatar */
    private function upsertAvatar(ConnectionInterface $target, array $avatar): void
    {
        [$sourceAvatar, $id, $filename, $language, $gender, $tone, $croppedUrl, $originalUrl] = $avatar;

        $target->table('admin_speaker_avatars')->updateOrInsert(
            ['id' => $id],
            [
                'filename' => $filename,
                'cropped_url' => $croppedUrl,
                'original_url' => $originalUrl,
                'language' => $language,
                'gender' => $gender,
                'tone' => $tone,
                'source_system' => ConvoLabAccountSource::CONVOLAB,
                'created_at' => $sourceAvatar->createdAt,
                'updated_at' => $sourceAvatar->updatedAt,
            ],
        );
    }

    private function sourceRequiredString(stdClass $row, string $property, int $maxLength): string
    {
        $value = $this->nullableString($row, $property, $maxLength);
        if ($value === null) {
            throw new RuntimeException("Convo Lab source field [{$property}] is required.");
        }

        return $value;
    }

    private function nullableString(stdClass $row, string $property, ?int $maxLength = null): ?string
    {
        if (! isset($row->{$property})) {
            return null;
        }

        $value = trim((string) $row->{$property});
        if ($value === '') {
            return null;
        }
        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new RuntimeException("Convo Lab source field [{$property}] exceeds {$maxLength} characters.");
        }

        return $value;
    }
}
