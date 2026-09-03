<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Support\ConvoLabAdminSourceUser;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final class SyncConvoLabAdminUsersAction
{
    /** @return array{int, list<string>} */
    public function handle(ConnectionInterface $source, ConnectionInterface $target): array
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
                $normalizedUsers = $this->normalizeUsers($users, $seenIds, $seenEmails);
                $learningOsOwnedIds = $this->learningOsOwnedIds($target, $normalizedUsers);
                $learningOsOwnedAvatars = $this->learningOsOwnedAvatars($target, $normalizedUsers);

                foreach ($normalizedUsers as $sourceUser) {
                    if (! $learningOsOwnedIds->has($sourceUser->id)) {
                        $this->syncUser($target, $sourceUser, $learningOsOwnedAvatars);
                    }

                    $sourceUserIds[] = $sourceUser->id;
                    $count++;
                }
            }, 'id');

        return [$count, $sourceUserIds];
    }

    /**
     * @param  iterable<int, stdClass>  $users
     * @param  array<string, true>  $seenIds
     * @param  array<string, true>  $seenEmails
     * @return list<ConvoLabAdminSourceUser>
     */
    private function normalizeUsers(iterable $users, array &$seenIds, array &$seenEmails): array
    {
        $normalizedUsers = [];

        foreach ($users as $sourceUser) {
            $normalized = $this->normalizeUser($sourceUser);
            $this->recordSourceIdentity($normalized, $seenIds, $seenEmails);
            $normalizedUsers[] = $normalized;
        }

        return $normalizedUsers;
    }

    private function normalizeUser(stdClass $sourceUser): ConvoLabAdminSourceUser
    {
        $id = strtolower(trim((string) $sourceUser->id));
        $email = strtolower(trim((string) $sourceUser->email));

        if (! Str::isUuid($id)) {
            throw new RuntimeException("Convo Lab user [{$sourceUser->id}] has an invalid UUID.");
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Convo Lab user [{$sourceUser->id}] has an invalid email.");
        }

        if (strlen($email) > 255) {
            throw new RuntimeException("Convo Lab user [{$sourceUser->id}] has an invalid email.");
        }

        return new ConvoLabAdminSourceUser($sourceUser, $id, $email);
    }

    /**
     * @param  array<string, true>  $seenIds
     * @param  array<string, true>  $seenEmails
     */
    private function recordSourceIdentity(
        ConvoLabAdminSourceUser $sourceUser,
        array &$seenIds,
        array &$seenEmails,
    ): void {
        if (isset($seenIds[$sourceUser->id])) {
            throw new RuntimeException('Convo Lab users must have unique IDs and email addresses.');
        }

        if (isset($seenEmails[$sourceUser->email])) {
            throw new RuntimeException('Convo Lab users must have unique IDs and email addresses.');
        }

        $seenIds[$sourceUser->id] = true;
        $seenEmails[$sourceUser->email] = true;
    }

    /**
     * @param  list<ConvoLabAdminSourceUser>  $sourceUsers
     * @return Collection<string, true>
     */
    private function learningOsOwnedIds(ConnectionInterface $target, array $sourceUsers): Collection
    {
        return $target->table('admin_user_projections')
            ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
            ->whereIn('convolab_id', $this->sourceIds($sourceUsers))
            ->pluck('convolab_id')
            ->mapWithKeys(static fn (string $id): array => [strtolower($id) => true]);
    }

    /**
     * @param  list<ConvoLabAdminSourceUser>  $sourceUsers
     * @return Collection<string, string|null>
     */
    private function learningOsOwnedAvatars(ConnectionInterface $target, array $sourceUsers): Collection
    {
        return $target->table('admin_user_projections')
            ->where('avatar_source_system', ConvoLabAccountSource::LEARNING_OS)
            ->whereIn('convolab_id', $this->sourceIds($sourceUsers))
            ->pluck('avatar_url', 'convolab_id')
            ->mapWithKeys(static fn (?string $url, string $id): array => [strtolower($id) => $url]);
    }

    /**
     * @param  list<ConvoLabAdminSourceUser>  $sourceUsers
     * @return list<string>
     */
    private function sourceIds(array $sourceUsers): array
    {
        return array_map(
            static fn (ConvoLabAdminSourceUser $sourceUser): string => $sourceUser->id,
            $sourceUsers,
        );
    }

    /** @param Collection<string, string|null> $learningOsOwnedAvatars */
    private function syncUser(
        ConnectionInterface $target,
        ConvoLabAdminSourceUser $sourceUser,
        Collection $learningOsOwnedAvatars,
    ): void {
        $targetUser = $this->resolveTargetUser($target, $sourceUser);
        $projectionAttributes = $this->projectionAttributes($sourceUser, $targetUser?->id);
        $this->preserveLearningOsAvatar($projectionAttributes, $sourceUser->id, $learningOsOwnedAvatars);
        $passwordHash = $this->validatedPasswordHash($sourceUser);

        if ($targetUser === null) {
            $targetUserId = $this->insertCanonicalUser(
                $target,
                $sourceUser,
                $projectionAttributes,
                $passwordHash,
            );
        } else {
            $targetUserId = $this->updateCanonicalUser($target, $sourceUser, $targetUser, $passwordHash);
        }

        $projectionAttributes['user_id'] = $targetUserId;
        $target->table('admin_user_projections')->updateOrInsert(
            ['convolab_id' => $sourceUser->id],
            $projectionAttributes,
        );
    }

    private function resolveTargetUser(ConnectionInterface $target, ConvoLabAdminSourceUser $sourceUser): ?stdClass
    {
        $targetById = $target->table('users')->where('convolab_id', $sourceUser->id)->first();
        $targetEmailMatches = $target->table('users')
            ->whereRaw('LOWER(email) = ?', [$sourceUser->email])
            ->limit(2)
            ->get();

        if ($targetEmailMatches->count() > 1) {
            throw new RuntimeException(
                "Convo Lab user [{$sourceUser->id}] matches multiple canonical email accounts.",
            );
        }

        $targetByEmail = $targetEmailMatches->first();
        $this->guardAgainstConflictingMatches($sourceUser, $targetById, $targetByEmail);
        $targetUser = $targetById ?? $targetByEmail;
        $this->guardAgainstReassignment($sourceUser, $targetUser);

        return $targetUser;
    }

    private function guardAgainstConflictingMatches(
        ConvoLabAdminSourceUser $sourceUser,
        ?stdClass $targetById,
        ?stdClass $targetByEmail,
    ): void {
        if ($targetById === null) {
            return;
        }

        if ($targetByEmail === null) {
            return;
        }

        if ($targetById->id !== $targetByEmail->id) {
            throw new RuntimeException(
                "Convo Lab user [{$sourceUser->id}] conflicts with an existing canonical email account.",
            );
        }
    }

    private function guardAgainstReassignment(ConvoLabAdminSourceUser $sourceUser, ?stdClass $targetUser): void
    {
        if ($targetUser === null) {
            return;
        }

        if ($targetUser->convolab_id === null) {
            return;
        }

        if (strtolower((string) $targetUser->convolab_id) !== $sourceUser->id) {
            throw new RuntimeException(
                "Canonical user [{$targetUser->id}] already belongs to another Convo Lab account.",
            );
        }
    }

    /** @return array<string, mixed> */
    private function projectionAttributes(ConvoLabAdminSourceUser $sourceUser, mixed $targetUserId): array
    {
        $row = $sourceUser->row;

        return [
            'user_id' => $targetUserId,
            'email' => trim((string) $row->email),
            'name' => $this->requiredString($row, 'name', 255, $row->email),
            'display_name' => $this->nullableString($row, 'displayName', 255),
            'avatar_color' => $this->nullableString($row, 'avatarColor', 32),
            'avatar_url' => $this->nullableString($row, 'avatarUrl'),
            'avatar_source_system' => ConvoLabAccountSource::CONVOLAB,
            'role' => $this->requiredString($row, 'role', 32, 'user'),
            'preferred_study_language' => $this->requiredString($row, 'preferredStudyLanguage', 16, 'ja'),
            'preferred_native_language' => $this->requiredString($row, 'preferredNativeLanguage', 16, 'en'),
            'proficiency_level' => $this->requiredString($row, 'proficiencyLevel', 32, 'beginner'),
            'onboarding_completed' => (bool) $row->onboardingCompleted,
            'seen_sample_content_guide' => (bool) $row->seenSampleContentGuide,
            'seen_custom_content_guide' => (bool) $row->seenCustomContentGuide,
            'email_verified' => (bool) $row->emailVerified,
            'email_verified_at' => $row->emailVerifiedAt,
            'created_at' => $row->createdAt,
            'updated_at' => $row->updatedAt,
            'source_system' => ConvoLabAccountSource::CONVOLAB,
        ];
    }

    /**
     * @param  array<string, mixed>  $projectionAttributes
     * @param  Collection<string, string|null>  $learningOsOwnedAvatars
     */
    private function preserveLearningOsAvatar(
        array &$projectionAttributes,
        string $sourceUserId,
        Collection $learningOsOwnedAvatars,
    ): void {
        if (! $learningOsOwnedAvatars->has($sourceUserId)) {
            return;
        }

        $projectionAttributes['avatar_url'] = $learningOsOwnedAvatars->get($sourceUserId);
        $projectionAttributes['avatar_source_system'] = ConvoLabAccountSource::LEARNING_OS;
    }

    private function validatedPasswordHash(ConvoLabAdminSourceUser $sourceUser): ?string
    {
        $passwordHash = $this->nullableString($sourceUser->row, 'password', 255);

        if ($passwordHash !== null && ! $this->isSupportedPasswordHash($passwordHash)) {
            throw new RuntimeException("Convo Lab user [{$sourceUser->id}] has an unsupported password hash.");
        }

        return $passwordHash;
    }

    /** @param array<string, mixed> $projectionAttributes */
    private function insertCanonicalUser(
        ConnectionInterface $target,
        ConvoLabAdminSourceUser $sourceUser,
        array $projectionAttributes,
        ?string $passwordHash,
    ): mixed {
        return $target->table('users')->insertGetId([
            'convolab_id' => $sourceUser->id,
            'name' => $projectionAttributes['name'],
            'email' => $projectionAttributes['email'],
            'email_verified_at' => $sourceUser->row->emailVerifiedAt,
            'password' => Hash::make(Str::random(64)),
            'convolab_email_normalized' => $sourceUser->email,
            'convolab_password_hash' => $passwordHash,
            'remember_token' => null,
            'created_at' => $sourceUser->row->createdAt,
            'updated_at' => $sourceUser->row->updatedAt,
        ]);
    }

    private function updateCanonicalUser(
        ConnectionInterface $target,
        ConvoLabAdminSourceUser $sourceUser,
        stdClass $targetUser,
        ?string $passwordHash,
    ): mixed {
        $target->table('users')->where('id', $targetUser->id)->update([
            'convolab_id' => $sourceUser->id,
            'convolab_email_normalized' => $sourceUser->email,
            'convolab_password_hash' => $passwordHash,
        ]);

        return $targetUser->id;
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

    private function isSupportedPasswordHash(string $passwordHash): bool
    {
        if (! preg_match('/^\$2[aby]\$(\d{2})\$[.\/A-Za-z0-9]{53}$/', $passwordHash, $matches)) {
            return false;
        }

        $cost = (int) $matches[1];

        return $cost >= 4 && $cost <= 31;
    }
}
