<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Models\ConvoLabOAuthIdentity;
use App\Domain\Auth\Results\ResolveConvoLabGoogleIdentityResult;
use App\Domain\Auth\Values\ConvoLabGoogleProfile;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ResolveConvoLabGoogleIdentityAction
{
    private const MAX_RESOLUTION_ATTEMPTS = 3;

    public function __construct(
        private readonly CreateConvoLabAccountProjectionAction $createAccountProjection,
    ) {}

    public function handle(
        string $providerId,
        string $email,
        string $name,
        ?string $avatarUrl,
        bool $emailVerified,
    ): ResolveConvoLabGoogleIdentityResult {
        if (! $emailVerified) {
            throw ConvoLabOAuthException::unverifiedEmail();
        }

        $profile = $this->profile($providerId, $email, $name, $avatarUrl);

        for ($attempt = 1; $attempt <= self::MAX_RESOLUTION_ATTEMPTS; $attempt++) {
            try {
                return $this->resolve($profile);
            } catch (UniqueConstraintViolationException) {
                // A concurrent first login may win either the email or provider identity insert.
                if ($attempt === self::MAX_RESOLUTION_ATTEMPTS) {
                    throw ConvoLabOAuthException::identityResolutionConflict();
                }
            }
        }

        throw new LogicException('OAuth identity resolution exhausted without a result.');
    }

    private function profile(
        string $providerId,
        string $email,
        string $name,
        ?string $avatarUrl,
    ): ConvoLabGoogleProfile {
        $profile = new ConvoLabGoogleProfile(
            trim($providerId),
            Str::lower(trim($email)),
            trim($name),
            $avatarUrl === null ? null : trim($avatarUrl),
        );

        if (! $this->hasValidProfileFields($profile)) {
            throw ConvoLabOAuthException::invalidProfile();
        }

        return $profile;
    }

    private function hasValidProfileFields(ConvoLabGoogleProfile $profile): bool
    {
        return $this->hasValidProviderId($profile)
            && $this->hasValidEmail($profile)
            && $this->hasValidName($profile)
            && $this->hasValidAvatarUrl($profile);
    }

    private function hasValidProviderId(ConvoLabGoogleProfile $profile): bool
    {
        return $profile->providerId !== '' && mb_strlen($profile->providerId) <= 255;
    }

    private function hasValidEmail(ConvoLabGoogleProfile $profile): bool
    {
        return $profile->email !== ''
            && mb_strlen($profile->email) <= 255
            && filter_var($profile->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function hasValidName(ConvoLabGoogleProfile $profile): bool
    {
        return $profile->name !== '' && mb_strlen($profile->name) <= 255;
    }

    private function hasValidAvatarUrl(ConvoLabGoogleProfile $profile): bool
    {
        return $profile->avatarUrl === null || (
            mb_strlen($profile->avatarUrl) <= 2048
            && filter_var($profile->avatarUrl, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($profile->avatarUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        );
    }

    private function resolve(ConvoLabGoogleProfile $profile): ResolveConvoLabGoogleIdentityResult
    {
        return DB::transaction(function () use ($profile): ResolveConvoLabGoogleIdentityResult {
            $identity = ConvoLabOAuthIdentity::query()
                ->where('provider', ConvoLabOAuthIdentity::GOOGLE_PROVIDER)
                ->where('provider_id', $profile->providerId)
                ->lockForUpdate()
                ->first();

            if ($identity instanceof ConvoLabOAuthIdentity) {
                // Once linked, Convo Lab profile edits are authoritative; Google only proves identity.
                return new ResolveConvoLabGoogleIdentityResult(
                    $this->accountForUser((int) $identity->user_id),
                    $identity->access_granted_at === null,
                    false,
                );
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$profile->email])
                ->lockForUpdate()
                ->first();
            $existingAccount = $this->existingAccount($user);
            $created = ! $existingAccount instanceof AdminUserProjection;

            $this->assertAccountCanConnect($existingAccount);
            $this->assertUserCanConnect($user);
            $user = $user instanceof User ? $user : $this->newUser($profile);

            $now = now();
            $this->saveUser($user, $profile, $created, $now);

            $account = $existingAccount ?? $this->createAccount($user, $profile, $now);
            $this->linkIdentity($user, $profile, $created, $now);

            return new ResolveConvoLabGoogleIdentityResult($account, $created, $created);
        }, 3);
    }

    private function existingAccount(?User $user): ?AdminUserProjection
    {
        if (! $user instanceof User) {
            return null;
        }

        return AdminUserProjection::query()
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function assertAccountCanConnect(?AdminUserProjection $account): void
    {
        if ($account instanceof AdminUserProjection && ! $account->email_verified) {
            throw ConvoLabOAuthException::existingAccountUnverified();
        }
    }

    private function assertUserCanConnect(?User $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        $otherIdentity = ConvoLabOAuthIdentity::query()
            ->where('user_id', $user->getKey())
            ->where('provider', ConvoLabOAuthIdentity::GOOGLE_PROVIDER)
            ->lockForUpdate()
            ->first();
        if ($otherIdentity instanceof ConvoLabOAuthIdentity) {
            throw ConvoLabOAuthException::identityAlreadyConnected();
        }
    }

    private function newUser(ConvoLabGoogleProfile $profile): User
    {
        $user = new User;
        $user->name = $profile->name;
        $user->email = $profile->email;
        $user->password = Str::random(64);

        return $user;
    }

    private function saveUser(
        User $user,
        ConvoLabGoogleProfile $profile,
        bool $created,
        DateTimeInterface $now,
    ): void {
        if ($user->email_verified_at === null) {
            $user->email_verified_at = $now;
        }
        if ($created) {
            $user->convolab_id = (string) Str::uuid();
            $user->convolab_email_normalized = $profile->email;
            $user->convolab_password_hash = null;
        }
        $user->save();
    }

    private function createAccount(
        User $user,
        ConvoLabGoogleProfile $profile,
        DateTimeInterface $now,
    ): AdminUserProjection {
        return $this->createAccountProjection->handle(
            user: $user,
            convoLabId: (string) $user->convolab_id,
            email: $profile->email,
            name: $profile->name,
            avatarUrl: $profile->avatarUrl,
            emailVerified: true,
            emailVerifiedAt: $now,
            now: $now,
        );
    }

    private function linkIdentity(
        User $user,
        ConvoLabGoogleProfile $profile,
        bool $created,
        DateTimeInterface $now,
    ): void {
        $identity = new ConvoLabOAuthIdentity;
        $identity->user_id = $user->getKey();
        $identity->provider = ConvoLabOAuthIdentity::GOOGLE_PROVIDER;
        $identity->provider_id = $profile->providerId;
        $identity->access_granted_at = $created ? null : $now;
        $identity->save();
    }

    private function accountForUser(int $userId): AdminUserProjection
    {
        $account = AdminUserProjection::query()
            ->where('user_id', $userId)
            ->first();

        if (! $account instanceof AdminUserProjection) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        return $account;
    }
}
