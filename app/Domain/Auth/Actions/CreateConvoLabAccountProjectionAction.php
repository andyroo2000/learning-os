<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use DateTimeInterface;

final class CreateConvoLabAccountProjectionAction
{
    public function handle(
        User $user,
        string $convoLabId,
        string $email,
        string $name,
        ?string $avatarUrl,
        bool $emailVerified,
        ?DateTimeInterface $emailVerifiedAt,
        DateTimeInterface $now,
    ): AdminUserProjection {
        $account = new AdminUserProjection;
        $account->convolab_id = $convoLabId;
        $account->user_id = $user->getKey();
        $account->email = $email;
        $account->name = $name;
        $account->display_name = null;
        $account->avatar_color = 'indigo';
        $account->avatar_url = $avatarUrl;
        $account->role = 'user';
        $account->preferred_study_language = 'ja';
        $account->preferred_native_language = 'en';
        $account->proficiency_level = 'beginner';
        $account->onboarding_completed = false;
        $account->seen_sample_content_guide = false;
        $account->seen_custom_content_guide = false;
        $account->email_verified = $emailVerified;
        $account->email_verified_at = $emailVerifiedAt;
        $account->created_at = $now;
        $account->updated_at = $now;
        $account->source_system = ConvoLabAccountSource::LEARNING_OS;
        $account->avatar_source_system = ConvoLabAccountSource::LEARNING_OS;
        $account->save();

        return $account;
    }
}
