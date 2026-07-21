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
            ->whereRaw('LOWER(admin_user_projections.email) = ?', [$email])
            ->first();
        $passwordHash = $projection?->getAttribute('convolab_password_hash');

        if (! is_string($passwordHash) || $passwordHash === '') {
            password_verify($password, self::DUMMY_PASSWORD_HASH);

            throw new InvalidConvoLabCredentialsException;
        }

        // Laravel's strict bcrypt wrapper rejects Node bcrypt's valid $2b$ prefix.
        if (! password_verify($password, $passwordHash)) {
            throw new InvalidConvoLabCredentialsException;
        }

        return $projection;
    }
}
