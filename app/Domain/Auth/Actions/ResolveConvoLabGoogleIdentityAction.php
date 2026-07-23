<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Models\ConvoLabOAuthIdentity;
use App\Domain\Auth\Results\ResolveConvoLabGoogleIdentityResult;
use App\Models\User;
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

        $providerId = trim($providerId);
        $email = Str::lower(trim($email));
        $name = trim($name);
        $avatarUrl = $avatarUrl === null ? null : trim($avatarUrl);

        for ($attempt = 1; $attempt <= self::MAX_RESOLUTION_ATTEMPTS; $attempt++) {
            try {
                return $this->resolve($providerId, $email, $name, $avatarUrl);
            } catch (UniqueConstraintViolationException) {
                // A concurrent first login may win either the email or provider identity insert.
                if ($attempt === self::MAX_RESOLUTION_ATTEMPTS) {
                    throw ConvoLabOAuthException::identityResolutionConflict();
                }
            }
        }

        throw new LogicException('OAuth identity resolution exhausted without a result.');
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
                // Once linked, Convo Lab profile edits are authoritative; Google only proves identity.
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

            if ($existingAccount instanceof AdminUserProjection && ! $existingAccount->email_verified) {
                throw ConvoLabOAuthException::existingAccountUnverified();
            }

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

            $account = $existingAccount ?? $this->createAccountProjection->handle(
                user: $user,
                convoLabId: (string) $user->convolab_id,
                email: $email,
                name: $name,
                avatarUrl: $avatarUrl,
                emailVerified: true,
                emailVerifiedAt: $now,
                now: $now,
            );
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
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        return $account;
    }
}
