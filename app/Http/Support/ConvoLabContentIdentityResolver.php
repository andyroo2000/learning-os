<?php

namespace App\Http\Support;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final class ConvoLabContentIdentityResolver
{
    public function __construct(
        private readonly RecordConvoLabImpersonation $recordImpersonation,
    ) {}

    public function actor(Request $request, bool $includeRole): ConvoLabContentIdentity
    {
        $userId = AuthenticatedUser::id($request);

        if (! ConvoLabRequestIdentity::allowsFirstPartySession($request)) {
            $convoLabUserId = ConvoLabRequestIdentity::userId($request);

            return new ConvoLabContentIdentity(
                $userId,
                is_string($convoLabUserId) ? $convoLabUserId : null,
                null,
            );
        }

        $role = $includeRole
            ? AdminUserProjection::query()
                ->where('user_id', $userId)
                ->value('role')
            : null;

        return new ConvoLabContentIdentity(
            $userId,
            $this->normalizeId($request->user()?->getAttribute('convolab_id')),
            is_string($role) ? $role : null,
        );
    }

    public function effective(
        Request $request,
        ConvoLabContentIdentity $actor,
        ?string $viewAs,
    ): ConvoLabContentIdentity {
        if ($viewAs === null || ! ConvoLabRequestIdentity::allowsFirstPartySession($request)) {
            return $actor;
        }

        if ($actor->role !== 'admin') {
            throw new AuthorizationException('Unauthorized impersonation attempt');
        }

        $target = AdminUserProjection::query()
            ->whereKey(strtolower($viewAs))
            ->first();

        if (! $target instanceof AdminUserProjection) {
            throw (new ModelNotFoundException)->setModel(AdminUserProjection::class);
        }

        ($this->recordImpersonation)(
            $request,
            $actor->convoLabUserId,
            (string) $target->convolab_id,
        );

        return new ConvoLabContentIdentity(
            (int) $target->user_id,
            strtolower((string) $target->convolab_id),
            (string) $target->role,
        );
    }

    private function normalizeId(mixed $value): ?string
    {
        return is_string($value) ? strtolower(trim($value)) : null;
    }
}
