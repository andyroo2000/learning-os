<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Models\ConvoLabOAuthIdentity;
use App\Domain\Auth\Results\ResolveConvoLabGoogleIdentityResult;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ResolveConvoLabGoogleIdentityAction
{
    public function handle(
        string $providerId,
        string $email,
        string $name,
        ?string $avatarUrl,
    ): ResolveConvoLabGoogleIdentityResult {
        $providerId = trim($providerId);
        $email = Str::lower(trim($email));
        $name = trim($name);
        $avatarUrl = $avatarUrl === null ? null : trim($avatarUrl);

        try {
            return $this->resolve($providerId, $email, $name, $avatarUrl);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first login may win either the email or provider identity insert.
            return $this->resolve($providerId, $email, $name, $avatarUrl);
        }
    }

    private function resolve(
        string $providerId,
        string $email,
        string $name,
        ?string $avatarUrl,
    ): ResolveConvoLabGoogleIdentityResult {
        return DB::transaction(function () use ($providerId, $email, $name, $avatarUrl): ResolveConvoLabGoogleIdentityResult {
            $identity = ConvoLabOAuthIdentity::query()
                ->where('provider', ConvoLabOAuthIdentity::GOOGLE_PROVIDER)
                ->where('provider_id', $providerId)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof ConvoLabOAuthIdentity) {
                return new ResolveConvoLabGoogleIdentityResult(
                    $this->accountForUser((int) $identity->user_id),
                    $identity->access_granted_at === null,
                    false,
                );
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();
            $existingAccount = $user instanceof User
                ? AdminUserProjection::query()
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first()
                : null;
            $created = ! $existingAccount instanceof AdminUserProjection;

            if ($user instanceof User) {
                $otherIdentity = ConvoLabOAuthIdentity::query()
                    ->where('user_id', $user->getKey())
                    ->where('provider', ConvoLabOAuthIdentity::GOOGLE_PROVIDER)
                    ->lockForUpdate()
                    ->first();
                if ($otherIdentity instanceof ConvoLabOAuthIdentity) {
                    throw ConvoLabOAuthException::identityAlreadyConnected();
                }
            }

            if (! $user instanceof User) {
                $user = new User;
                $user->name = $name;
                $user->email = $email;
                $user->password = Str::random(64);
            }

            $now = now();
            if ($user->email_verified_at === null) {
                $user->email_verified_at = $now;
            }
            if ($created) {
                $user->convolab_id = (string) Str::uuid();
                $user->convolab_email_normalized = $email;
                $user->convolab_password_hash = null;
            }
            $user->save();

            $account = $existingAccount ?? $this->createAccount($user, $email, $name, $avatarUrl, $now);
            if (! $created && ! $account->email_verified) {
                $account->email_verified = true;
                $account->email_verified_at = $account->email_verified_at ?? $now;
                $account->updated_at = $now;
                $account->save();
            }

            $identity = new ConvoLabOAuthIdentity;
            $identity->user_id = $user->getKey();
            $identity->provider = ConvoLabOAuthIdentity::GOOGLE_PROVIDER;
            $identity->provider_id = $providerId;
            $identity->access_granted_at = $created ? null : $now;
            $identity->save();

            return new ResolveConvoLabGoogleIdentityResult($account, $created, $created);
        }, 3);
    }

    private function accountForUser(int $userId): AdminUserProjection
    {
        $account = AdminUserProjection::query()
            ->where('user_id', $userId)
            ->first();

        if (! $account instanceof AdminUserProjection) {
            throw new LogicException('A Convo Lab OAuth identity has no account projection.');
        }

        return $account;
    }

    private function createAccount(
        User $user,
        string $email,
        string $name,
        ?string $avatarUrl,
        DateTimeInterface $now,
    ): AdminUserProjection {
        $convoLabId = (string) $user->convolab_id;
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
        $account->email_verified = true;
        $account->email_verified_at = $now;
        $account->created_at = $now;
        $account->updated_at = $now;
        $account->source_system = ConvoLabAccountSource::LEARNING_OS;
        $account->avatar_source_system = ConvoLabAccountSource::LEARNING_OS;
        $account->save();

        return $account;
    }
}
