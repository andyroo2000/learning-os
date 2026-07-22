<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Results\AdminProjectionSyncResult;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

class SyncConvoLabAdminProjectionAction
{
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

        [$users, $sourceUserIds] = $this->syncUsers($source, $target);
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

    /** @return array{int, list<string>} */
    private function syncUsers(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $count = 0;
        $sourceUserIds = [];
        $seenEmails = [];
        $seenIds = [];

        $source->table('User')
            ->chunkById(200, function ($users) use (
                $target,
                &$count,
                &$sourceUserIds,
                &$seenEmails,
                &$seenIds,
            ): void {
                $normalizedUsers = [];
                foreach ($users as $sourceUser) {
                    $convoLabId = strtolower(trim((string) $sourceUser->id));
                    $email = strtolower(trim((string) $sourceUser->email));

                    if (! Str::isUuid($convoLabId)) {
                        throw new RuntimeException("Convo Lab user [{$sourceUser->id}] has an invalid UUID.");
                    }
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                        throw new RuntimeException("Convo Lab user [{$sourceUser->id}] has an invalid email.");
                    }
                    if (isset($seenIds[$convoLabId]) || isset($seenEmails[$email])) {
                        throw new RuntimeException('Convo Lab users must have unique IDs and email addresses.');
                    }

                    $seenIds[$convoLabId] = true;
                    $seenEmails[$email] = true;
                    $normalizedUsers[] = [$sourceUser, $convoLabId, $email];
                }

                $learningOsOwnedIds = $target->table('admin_user_projections')
                    ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
                    ->whereIn('convolab_id', array_column($normalizedUsers, 1))
                    ->pluck('convolab_id')
                    ->mapWithKeys(static fn (string $id): array => [strtolower($id) => true]);
                $learningOsOwnedAvatars = $target->table('admin_user_projections')
                    ->where('avatar_source_system', ConvoLabAccountSource::LEARNING_OS)
                    ->whereIn('convolab_id', array_column($normalizedUsers, 1))
                    ->pluck('avatar_url', 'convolab_id')
                    ->mapWithKeys(static fn (?string $url, string $id): array => [strtolower($id) => $url]);

                foreach ($normalizedUsers as [$sourceUser, $convoLabId, $email]) {
                    if ($learningOsOwnedIds->has($convoLabId)) {
                        $sourceUserIds[] = $convoLabId;
                        $count++;

                        continue;
                    }
                    $targetById = $target->table('users')->where('convolab_id', $convoLabId)->first();
                    $targetEmailMatches = $target->table('users')
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->limit(2)
                        ->get();
                    if ($targetEmailMatches->count() > 1) {
                        throw new RuntimeException(
                            "Convo Lab user [{$convoLabId}] matches multiple canonical email accounts.",
                        );
                    }
                    $targetByEmail = $targetEmailMatches->first();
                    if ($targetById !== null && $targetByEmail !== null && $targetById->id !== $targetByEmail->id) {
                        throw new RuntimeException(
                            "Convo Lab user [{$convoLabId}] conflicts with an existing canonical email account.",
                        );
                    }

                    $targetUser = $targetById ?? $targetByEmail;
                    if (
                        $targetUser !== null
                        && $targetUser->convolab_id !== null
                        && strtolower((string) $targetUser->convolab_id) !== $convoLabId
                    ) {
                        throw new RuntimeException(
                            "Canonical user [{$targetUser->id}] already belongs to another Convo Lab account.",
                        );
                    }

                    $projectionAttributes = [
                        'user_id' => $targetUser?->id,
                        'email' => trim((string) $sourceUser->email),
                        'name' => $this->requiredString($sourceUser, 'name', 255, $sourceUser->email),
                        'display_name' => $this->nullableString($sourceUser, 'displayName', 255),
                        'avatar_color' => $this->nullableString($sourceUser, 'avatarColor', 32),
                        'avatar_url' => $this->nullableString($sourceUser, 'avatarUrl'),
                        'avatar_source_system' => ConvoLabAccountSource::CONVOLAB,
                        'role' => $this->requiredString($sourceUser, 'role', 32, 'user'),
                        'preferred_study_language' => $this->requiredString(
                            $sourceUser,
                            'preferredStudyLanguage',
                            16,
                            'ja',
                        ),
                        'preferred_native_language' => $this->requiredString(
                            $sourceUser,
                            'preferredNativeLanguage',
                            16,
                            'en',
                        ),
                        'proficiency_level' => $this->requiredString(
                            $sourceUser,
                            'proficiencyLevel',
                            32,
                            'beginner',
                        ),
                        'onboarding_completed' => (bool) $sourceUser->onboardingCompleted,
                        'seen_sample_content_guide' => (bool) $sourceUser->seenSampleContentGuide,
                        'seen_custom_content_guide' => (bool) $sourceUser->seenCustomContentGuide,
                        'email_verified' => (bool) $sourceUser->emailVerified,
                        'email_verified_at' => $sourceUser->emailVerifiedAt,
                        'created_at' => $sourceUser->createdAt,
                        'updated_at' => $sourceUser->updatedAt,
                        'source_system' => ConvoLabAccountSource::CONVOLAB,
                    ];
                    if ($learningOsOwnedAvatars->has($convoLabId)) {
                        $projectionAttributes['avatar_url'] = $learningOsOwnedAvatars->get($convoLabId);
                        $projectionAttributes['avatar_source_system'] = ConvoLabAccountSource::LEARNING_OS;
                    }

                    $passwordHash = $this->nullableString($sourceUser, 'password', 255);
                    if ($passwordHash !== null && ! $this->isSupportedPasswordHash($passwordHash)) {
                        throw new RuntimeException("Convo Lab user [{$convoLabId}] has an unsupported password hash.");
                    }

                    if ($targetUser === null) {
                        $targetUserId = $target->table('users')->insertGetId([
                            'convolab_id' => $convoLabId,
                            'name' => $projectionAttributes['name'],
                            'email' => $projectionAttributes['email'],
                            'email_verified_at' => $sourceUser->emailVerifiedAt,
                            'password' => Hash::make(Str::random(64)),
                            'convolab_email_normalized' => $email,
                            'convolab_password_hash' => $passwordHash,
                            'remember_token' => null,
                            'created_at' => $sourceUser->createdAt,
                            'updated_at' => $sourceUser->updatedAt,
                        ]);
                    } else {
                        $targetUserId = $targetUser->id;
                        $target->table('users')->where('id', $targetUserId)->update([
                            'convolab_id' => $convoLabId,
                            'convolab_email_normalized' => $email,
                            'convolab_password_hash' => $passwordHash,
                        ]);
                    }

                    $projectionAttributes['user_id'] = $targetUserId;
                    $target->table('admin_user_projections')->updateOrInsert(
                        ['convolab_id' => $convoLabId],
                        $projectionAttributes,
                    );

                    $sourceUserIds[] = $convoLabId;
                    $count++;
                }
            }, 'id');

        return [$count, $sourceUserIds];
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

    private function requiredString(stdClass $row, string $property, int $maxLength, string $fallback): string
    {
        $value = trim((string) ($row->{$property} ?? ''));
        $value = $value === '' ? $fallback : $value;

        if (mb_strlen($value) > $maxLength) {
            throw new RuntimeException("Convo Lab source field [{$property}] exceeds {$maxLength} characters.");
        }

        return $value;
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

    private function isSupportedPasswordHash(string $passwordHash): bool
    {
        if (! preg_match('/^\$2[aby]\$(\d{2})\$[.\/A-Za-z0-9]{53}$/', $passwordHash, $matches)) {
            return false;
        }

        $cost = (int) $matches[1];

        return $cost >= 4 && $cost <= 31;
    }
}
