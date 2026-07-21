<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminUsersAction
{
    /** @return LengthAwarePaginator<int, AdminUserProjection> */
    public function handle(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return AdminUserProjection::query()
            ->when($search !== null, fn ($query) => $query->where(function ($query) use ($search): void {
                $pattern = '%'.$this->escapeLike($search).'%';

                $query->whereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(display_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            }))
            ->with(['user' => fn ($query) => $query->withCount([
                'convoLabContentEpisodes as episodes_count',
                'convoLabContentCourses as courses_count',
            ])])
            ->orderByDesc('created_at')
            ->orderByDesc('convolab_id')
            ->paginate(perPage: $limit, page: $page);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
