<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class CreateAdminInviteCodeAction
{
    public function handle(?string $customCode): AdminInviteCode
    {
        $code = $customCode === null || $customCode === ''
            ? strtoupper(bin2hex(random_bytes(4)))
            : $customCode;

        if (preg_match('/^[A-Za-z0-9]{6,20}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Custom code must be 6-20 alphanumeric characters');
        }

        try {
            $invite = new AdminInviteCode;
            $invite->id = (string) Str::uuid();
            $invite->code = $code;
            $invite->used_by = null;
            $invite->convolab_used_by = null;
            $invite->used_at = null;
            $invite->created_at = now();
            $invite->source_system = ConvoLabAccountSource::LEARNING_OS;
            $invite->save();
        } catch (UniqueConstraintViolationException) {
            throw AdminMutationException::duplicateInvite();
        }

        return $invite;
    }
}
