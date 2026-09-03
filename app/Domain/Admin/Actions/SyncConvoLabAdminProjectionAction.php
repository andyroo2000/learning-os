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
        private readonly SyncConvoLabSpeakerAvatarsAction $syncSpeakerAvatars,
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
        $speakerAvatars = $this->syncSpeakerAvatars->handle($source, $target);
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
                $normalizedInviteCodes = $this->normalizeInviteCodes($inviteCodes);
                $count += $this->syncInviteCodeChunk($target, $normalizedInviteCodes, $sourceIds);
            }, 'id');

        $target->table('admin_invite_codes')
            ->where('source_system', ConvoLabAccountSource::CONVOLAB)
            ->when($sourceIds !== [], fn ($query) => $query->whereNotIn('id', $sourceIds))
            ->delete();

        return $count;
    }

    /**
     * @param  iterable<int, stdClass>  $inviteCodes
     * @return list<array{stdClass, string, string|null, string}>
     */
    private function normalizeInviteCodes(iterable $inviteCodes): array
    {
        $normalized = [];

        foreach ($inviteCodes as $inviteCode) {
            $normalized[] = $this->normalizeInviteCode($inviteCode);
        }

        return $normalized;
    }

    /** @return array{stdClass, string, string|null, string} */
    private function normalizeInviteCode(stdClass $inviteCode): array
    {
        $id = strtolower(trim((string) $inviteCode->id));

        if (! Str::isUuid($id)) {
            throw new RuntimeException("Convo Lab invite code [{$inviteCode->id}] has an invalid UUID.");
        }

        $convoLabUsedBy = $this->normalizedInviteCodeUserId($inviteCode->usedBy, $id);
        $code = trim((string) $inviteCode->code);
        if ($code === '' || mb_strlen($code) > 20) {
            throw new RuntimeException("Convo Lab invite code [{$id}] has an invalid code value.");
        }

        return [$inviteCode, $id, $convoLabUsedBy, $code];
    }

    private function normalizedInviteCodeUserId(mixed $value, string $inviteCodeId): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        if (! Str::isUuid($normalized)) {
            throw new RuntimeException("Convo Lab invite code [{$inviteCodeId}] has an invalid user UUID.");
        }

        return $normalized;
    }

    /**
     * @param  list<array{stdClass, string, string|null, string}>  $inviteCodes
     * @param  list<string>  $sourceIds
     */
    private function syncInviteCodeChunk(
        ConnectionInterface $target,
        array $inviteCodes,
        array &$sourceIds,
    ): int {
        $learningOsOwnedIds = $target->table('admin_invite_codes')
            ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
            ->whereIn('id', array_column($inviteCodes, 1))
            ->pluck('id')
            ->mapWithKeys(static fn (string $id): array => [strtolower($id) => true]);
        $tombstonedIds = $target->table('admin_invite_code_tombstones')
            ->whereIn('invite_code_id', array_column($inviteCodes, 1))
            ->pluck('invite_code_id')
            ->mapWithKeys(static fn (string $id): array => [strtolower($id) => true]);
        $count = 0;

        foreach ($inviteCodes as [$inviteCode, $id, $convoLabUsedBy, $code]) {
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

        return $count;
    }
}
