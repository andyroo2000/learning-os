<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Results\AdminProjectionSyncResult;
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
    ): AdminProjectionSyncResult {
        foreach (['User', 'InviteCode'] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Source database is missing expected Convo Lab table [{$table}].");
            }
        }

        $target->table('users')->whereNotNull('convolab_id')->update([
            'convolab_admin_visible' => false,
        ]);
        $users = $this->syncUsers($source, $target);
        $inviteCodes = $this->syncInviteCodes($source, $target);

        return new AdminProjectionSyncResult($users, $inviteCodes);
    }

    private function syncUsers(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;
        $seenEmails = [];
        $seenIds = [];

        $source->table('User')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(200, function ($users) use ($target, &$count, &$seenEmails, &$seenIds): void {
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

                    $attributes = [
                        'convolab_id' => $convoLabId,
                        'convolab_admin_visible' => true,
                        'name' => $this->requiredString($sourceUser, 'name', 255, $sourceUser->email),
                        'email' => trim((string) $sourceUser->email),
                        'display_name' => $this->nullableString($sourceUser, 'displayName', 255),
                        'avatar_color' => $this->nullableString($sourceUser, 'avatarColor', 32),
                        'avatar_url' => $this->nullableString($sourceUser, 'avatarUrl'),
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
                        'onboarding_completed' => (bool) $sourceUser->onboardingCompleted,
                        'email_verified_at' => $sourceUser->emailVerifiedAt,
                        'created_at' => $sourceUser->createdAt,
                        'updated_at' => $sourceUser->updatedAt,
                    ];

                    if ($targetUser === null) {
                        $attributes['password'] = Hash::make(Str::random(64));
                        $attributes['remember_token'] = null;
                        $target->table('users')->insert($attributes);
                    } else {
                        $target->table('users')->where('id', $targetUser->id)->update($attributes);
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function syncInviteCodes(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;
        $sourceIds = [];

        $source->table('InviteCode')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(200, function ($inviteCodes) use ($target, &$count, &$sourceIds): void {
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
                        ],
                    );
                    $sourceIds[] = $id;
                    $count++;
                }
            });

        $target->table('admin_invite_codes')
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
