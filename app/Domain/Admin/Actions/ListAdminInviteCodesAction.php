<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminInviteCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminInviteCodesAction
{
    /** @return LengthAwarePaginator<int, AdminInviteCode> */
    public function handle(int $page, int $limit): LengthAwarePaginator
    {
        return AdminInviteCode::query()
            ->with('adminUserProjection')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $limit, page: $page);
    }
}
