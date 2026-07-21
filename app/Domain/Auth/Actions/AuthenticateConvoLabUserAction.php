<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Exceptions\InvalidConvoLabCredentialsException;
use Illuminate\Support\Str;

final class AuthenticateConvoLabUserAction
{
    private const DUMMY_PASSWORD_HASH = '$2b$10$5607VcqBDio.lZukOb2s2euSQcUNC0ImK/yy8rn959xVUMn2g1DC6';

    public function handle(string $email, string $password): AdminUserProjection
    {
        $email = Str::lower(trim($email));
        $projection = AdminUserProjection::query()
            ->select('admin_user_projections.*')
            ->addSelect('users.convolab_password_hash')
            ->join('users', 'users.id', '=', 'admin_user_projections.user_id')
            ->where('users.convolab_email_normalized', $email)
            ->first();
        $passwordHash = $projection?->getAttribute('convolab_password_hash');

        if (! is_string($passwordHash) || $passwordHash === '') {
            password_verify($password, self::DUMMY_PASSWORD_HASH);

            throw new InvalidConvoLabCredentialsException;
        }

        // Laravel's configured bcrypt hasher rejects Node's $2b$ prefix during algorithm verification.
        if (! password_verify($password, $passwordHash)) {
            throw new InvalidConvoLabCredentialsException;
        }

        return $projection->makeHidden('convolab_password_hash');
    }
}
