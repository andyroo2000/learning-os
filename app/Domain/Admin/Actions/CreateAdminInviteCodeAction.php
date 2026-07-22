<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class CreateAdminInviteCodeAction
{
    private const GENERATED_CODE_ATTEMPTS = 3;

    /** @var \Closure(): string */
    private readonly \Closure $generateCode;

    /** @param (callable(): string)|null $generateCode */
    public function __construct(?callable $generateCode = null)
    {
        $this->generateCode = $generateCode === null
            ? static fn (): string => strtoupper(bin2hex(random_bytes(4)))
            : \Closure::fromCallable($generateCode);
    }

    public function handle(?string $customCode): AdminInviteCode
    {
        if ($customCode === null || $customCode === '') {
            for ($attempt = 0; $attempt < self::GENERATED_CODE_ATTEMPTS; $attempt++) {
                try {
                    return $this->create(($this->generateCode)());
                } catch (UniqueConstraintViolationException) {
                    // A generated collision is internal; retry with a fresh code.
                }
            }

            throw AdminMutationException::inviteGenerationFailed();
        }

        if (preg_match('/^[A-Za-z0-9]{6,20}$/', $customCode) !== 1) {
            throw new \InvalidArgumentException('Custom code must be 6-20 alphanumeric characters');
        }

        try {
            return $this->create($customCode);
        } catch (UniqueConstraintViolationException) {
            throw AdminMutationException::duplicateInvite();
        }
    }

    private function create(string $code): AdminInviteCode
    {
        $invite = new AdminInviteCode;
        $invite->id = (string) Str::uuid();
        $invite->code = $code;
        $invite->used_by = null;
        $invite->convolab_used_by = null;
        $invite->used_at = null;
        $invite->created_at = now();
        $invite->source_system = ConvoLabAccountSource::LEARNING_OS;
        $invite->save();

        return $invite;
    }
}
