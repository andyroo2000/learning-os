<?php

namespace App\Domain\Admin\Actions;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminUsersAction
{
    /** @return LengthAwarePaginator<int, User> */
    public function handle(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return User::query()
            ->where('convolab_admin_visible', true)
            ->when($search !== null, fn ($query) => $query->where(function ($query) use ($search): void {
                $pattern = '%'.$this->escapeLike($search).'%';

                $query->whereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(display_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            }))
            ->withCount([
                'contentEpisodes as episodes_count',
                'contentCourses as courses_count',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $limit, page: $page);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
