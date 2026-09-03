<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Results\AdminProjectionSyncResult;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

class SyncConvoLabAdminProjectionAction
{
    public function __construct(
        private readonly SyncConvoLabAdminUsersAction $syncUsers,
    ) {}

    public function handle(
        ConnectionInterface $source,
        ConnectionInterface $target,
        bool $allowEmptySource = false,
    ): AdminProjectionSyncResult {
        foreach (['User', 'InviteCode', 'SpeakerAvatar'] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Source database is missing expected Convo Lab table [{$table}].");
            }
        }

        $this->guardAgainstEmptySourceTable(
            $source,
            $target,
            'User',
            'admin_user_projections',
            $allowEmptySource,
        );
        $this->guardAgainstEmptySourceTable(
            $source,
            $target,
            'InviteCode',
            'admin_invite_codes',
            $allowEmptySource,
        );
        $this->guardAgainstEmptySourceTable(
            $source,
            $target,
            'SpeakerAvatar',
            'admin_speaker_avatars',
            $allowEmptySource,
        );

        [$users, $sourceUserIds] = $this->syncUsers->handle($source, $target);
        $inviteCodes = $this->syncInviteCodes($source, $target);
        $speakerAvatars = $this->syncSpeakerAvatars($source, $target);
        $target->table('admin_user_projections')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->when($sourceUserIds !== [], fn ($query) => $query->whereNotIn('convolab_id', $sourceUserIds))
            ->delete();

        return new AdminProjectionSyncResult($users, $inviteCodes, $speakerAvatars);
    }

    private function guardAgainstEmptySourceTable(
        ConnectionInterface $source,
        ConnectionInterface $target,
        string $sourceTable,
        string $targetTable,
        bool $allowEmptySource,
    ): void {
        if (
            ! $allowEmptySource
            && ! $source->table($sourceTable)->exists()
            && $target->table($targetTable)
                ->where('source_system', ConvoLabAccountSource::CONVOLAB)
                ->exists()
        ) {
            throw new RuntimeException(
                "The Convo Lab source table [{$sourceTable}] is empty while [{$targetTable}] is not. "
                .'Re-run with --allow-empty-source to confirm removal.',
            );
        }
    }

    private function syncInviteCodes(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;
        $sourceIds = [];

        $source->table('InviteCode')
            ->chunkById(200, function ($inviteCodes) use ($target, &$count, &$sourceIds): void {
                $normalizedInviteCodes = [];
                foreach ($inviteCodes as $inviteCode) {
                    $id = strtolower(trim((string) $inviteCode->id));
                    $convoLabUsedBy = $inviteCode->usedBy === null
                        ? null
                        : strtolower(trim((string) $inviteCode->usedBy));

                    if (! Str::isUuid($id)) {
                        throw new RuntimeException("Convo Lab invite code [{$inviteCode->id}] has an invalid UUID.");
                    }
                    if ($convoLabUsedBy !== null && ! Str::isUuid($convoLabUsedBy)) {
                        throw new RuntimeException("Convo Lab invite code [{$id}] has an invalid user UUID.");
                    }
                    $code = trim((string) $inviteCode->code);
                    if ($code === '' || mb_strlen($code) > 20) {
                        throw new RuntimeException("Convo Lab invite code [{$id}] has an invalid code value.");
                    }
                    $normalizedInviteCodes[] = [$inviteCode, $id, $convoLabUsedBy, $code];
                }

                $learningOsOwnedIds = $target->table('admin_invite_codes')
                    ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
                    ->whereIn('id', array_column($normalizedInviteCodes, 1))
                    ->pluck('id')
                    ->mapWithKeys(static fn (string $id): array => [strtolower($id) => true]);
                $tombstonedIds = $target->table('admin_invite_code_tombstones')
                    ->whereIn('invite_code_id', array_column($normalizedInviteCodes, 1))
                    ->pluck('invite_code_id')
                    ->mapWithKeys(static fn (string $id): array => [strtolower($id) => true]);

                foreach ($normalizedInviteCodes as [$inviteCode, $id, $convoLabUsedBy, $code]) {
                    if ($learningOsOwnedIds->has($id) || $tombstonedIds->has($id)) {
                        $sourceIds[] = $id;
                        $count++;

                        continue;
                    }

                    $usedBy = $convoLabUsedBy === null
                        ? null
                        : $target->table('users')->where('convolab_id', $convoLabUsedBy)->value('id');
                    if ($convoLabUsedBy !== null && $usedBy === null) {
                        throw new RuntimeException("Convo Lab invite code [{$id}] references an unknown user.");
                    }

                    $target->table('admin_invite_codes')->updateOrInsert(
                        ['id' => $id],
                        [
                            'code' => $code,
                            'used_by' => $usedBy,
                            'convolab_used_by' => $convoLabUsedBy,
                            'used_at' => $inviteCode->usedAt,
                            'created_at' => $inviteCode->createdAt,
                            'source_system' => ConvoLabAccountSource::CONVOLAB,
                        ],
                    );
                    $sourceIds[] = $id;
                    $count++;
                }
            }, 'id');

        $target->table('admin_invite_codes')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->when($sourceIds !== [], fn ($query) => $query->whereNotIn('id', $sourceIds))
            ->delete();

        return $count;
    }

    private function syncSpeakerAvatars(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;
        $sourceIds = [];
        $seenFilenames = [];

        $source->table('SpeakerAvatar')
            ->chunkById(200, function ($avatars) use (
                $target,
                &$count,
                &$sourceIds,
                &$seenFilenames,
            ): void {
                $normalizedAvatars = [];
                foreach ($avatars as $avatar) {
                    $id = strtolower(trim((string) $avatar->id));
                    if (! Str::isUuid($id)) {
                        throw new RuntimeException("Convo Lab speaker avatar [{$avatar->id}] has an invalid UUID.");
                    }

                    $filename = strtolower($this->sourceRequiredString($avatar, 'filename', 255));
                    if (preg_match('/^ja-(male|female)-(casual|polite|formal)\.(jpg|jpeg|png|webp)$/', $filename) !== 1) {
                        throw new RuntimeException("Convo Lab speaker avatar [{$id}] has an invalid filename.");
                    }
                    if (isset($seenFilenames[$filename])) {
                        throw new RuntimeException('Convo Lab speaker avatars must have unique filenames.');
                    }
                    $seenFilenames[$filename] = true;

                    $language = strtolower($this->sourceRequiredString($avatar, 'language', 16));
                    $gender = strtolower($this->sourceRequiredString($avatar, 'gender', 16));
                    $tone = strtolower($this->sourceRequiredString($avatar, 'tone', 16));
                    if ($language !== 'ja' || ! in_array($gender, ['male', 'female'], true)
                        || ! in_array($tone, ['casual', 'polite', 'formal'], true)) {
                        throw new RuntimeException("Convo Lab speaker avatar [{$id}] has invalid voice metadata.");
                    }

                    $normalizedAvatars[] = [
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

                $ids = array_column($normalizedAvatars, 1);
                $filenames = array_column($normalizedAvatars, 2);
                $learningOsOwned = $target->table('admin_speaker_avatars')
                    ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
                    ->where(function ($query) use ($ids, $filenames): void {
                        $query->whereIn('id', $ids)->orWhereIn('filename', $filenames);
                    })
                    ->get(['id', 'filename']);
                $ownedIds = $learningOsOwned->pluck('id')->mapWithKeys(
                    static fn (string $id): array => [strtolower($id) => true],
                );
                $ownedFilenames = $learningOsOwned->pluck('filename')->mapWithKeys(
                    static fn (string $filename): array => [strtolower($filename) => true],
                );

                $sourceIdByFilename = collect($normalizedAvatars)->mapWithKeys(
                    static fn (array $avatar): array => [$avatar[2] => $avatar[1]],
                );
                $rotatedSourceIds = $target->table('admin_speaker_avatars')
                    ->where('source_system', ConvoLabAccountSource::CONVOLAB)
                    ->whereIn('filename', $filenames)
                    ->get(['id', 'filename'])
                    ->filter(static fn (stdClass $avatar): bool => strtolower((string) $avatar->id)
                        !== $sourceIdByFilename->get(strtolower((string) $avatar->filename)))
                    ->pluck('id');
                if ($rotatedSourceIds->isNotEmpty()) {
                    $target->table('admin_speaker_avatars')
                        ->where('source_system', ConvoLabAccountSource::CONVOLAB)
                        ->whereIn('id', $rotatedSourceIds)
                        ->delete();
                }

                foreach ($normalizedAvatars as [
                    $avatar,
                    $id,
                    $filename,
                    $language,
                    $gender,
                    $tone,
                    $croppedUrl,
                    $originalUrl,
                ]) {
                    if ($ownedIds->has($id) || $ownedFilenames->has($filename)) {
                        $sourceIds[] = $id;
                        $count++;

                        continue;
                    }

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
                            'created_at' => $avatar->createdAt,
                            'updated_at' => $avatar->updatedAt,
                        ],
                    );
                    $sourceIds[] = $id;
                    $count++;
                }
            }, 'id');

        $target->table('admin_speaker_avatars')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->when($sourceIds !== [], fn ($query) => $query->whereNotIn('id', $sourceIds))
            ->delete();

        return $count;
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
