<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\ConvoLabSignupException;
use App\Domain\Auth\Results\RegisterConvoLabUserResult;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegisterConvoLabUserAction
{
    public function handle(
        string $email,
        string $password,
        string $name,
        string $inviteCode,
    ): RegisterConvoLabUserResult {
        $email = Str::lower(trim($email));
        $name = trim($name);
        $inviteCode = trim($inviteCode);

        try {
            return DB::transaction(function () use ($email, $password, $name, $inviteCode): RegisterConvoLabUserResult {
                $invite = AdminInviteCode::query()
                    ->where('code', $inviteCode)
                    ->lockForUpdate()
                    ->first();
                if (! $invite instanceof AdminInviteCode) {
                    throw ConvoLabSignupException::invalidInvite();
                }

                $credentialUser = User::query()
                    ->where('convolab_email_normalized', $email)
                    ->lockForUpdate()
                    ->first();
                $account = $credentialUser instanceof User
                    ? AdminUserProjection::query()
                        ->where('user_id', $credentialUser->getKey())
                        ->lockForUpdate()
                        ->first()
                    : null;

                if ($account instanceof AdminUserProjection) {
                    return $this->retryExistingSignup($account, $credentialUser, $invite, $password);
                }
                if ($invite->used_by !== null || $invite->convolab_used_by !== null) {
                    throw ConvoLabSignupException::usedInvite();
                }
                if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw ConvoLabSignupException::accountExists();
                }

                $convoLabId = (string) Str::uuid();
                $now = now();
                $user = new User;
                $user->convolab_id = $convoLabId;
                $user->name = $name;
                $user->email = $email;
                $user->email_verified_at = null;
                $user->password = $password;
                $user->convolab_email_normalized = $email;
                $user->remember_token = null;

                $passwordHash = $user->getAttribute('password');
                if (! is_string($passwordHash) || $passwordHash === '') {
                    throw new \LogicException('Convo Lab signup did not produce a password hash.');
                }
                $user->convolab_password_hash = $passwordHash;
                $user->save();

                $account = new AdminUserProjection;
                $account->convolab_id = $convoLabId;
                $account->user_id = $user->getKey();
                $account->email = $email;
                $account->name = $name;
                $account->display_name = null;
                $account->avatar_color = 'indigo';
                $account->avatar_url = null;
                $account->role = 'user';
                $account->preferred_study_language = 'ja';
                $account->preferred_native_language = 'en';
                $account->proficiency_level = 'beginner';
                $account->onboarding_completed = false;
                $account->seen_sample_content_guide = false;
                $account->seen_custom_content_guide = false;
                $account->email_verified = false;
                $account->email_verified_at = null;
                $account->created_at = $now;
                $account->updated_at = $now;
                $account->source_system = ConvoLabAccountSource::LEARNING_OS;
                $account->save();

                $invite->used_by = $user->getKey();
                $invite->convolab_used_by = $convoLabId;
                $invite->used_at = $now;
                $invite->source_system = ConvoLabAccountSource::LEARNING_OS;
                $invite->save();

                return new RegisterConvoLabUserResult($account, true);
            });
        } catch (UniqueConstraintViolationException) {
            throw ConvoLabSignupException::accountExists();
        }
    }

    private function retryExistingSignup(
        AdminUserProjection $account,
        User $user,
        AdminInviteCode $invite,
        string $password,
    ): RegisterConvoLabUserResult {
        if (! hash_equals((string) $account->convolab_id, (string) $invite->convolab_used_by)) {
            throw ConvoLabSignupException::accountExists();
        }

        $passwordHash = $user->getAttribute('convolab_password_hash');
        if (! is_string($passwordHash) || ! password_verify($password, $passwordHash)) {
            throw ConvoLabSignupException::invalidRetryCredentials();
        }

        return new RegisterConvoLabUserResult($account, false);
    }
}
