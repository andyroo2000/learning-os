<?php

namespace App\Domain\FeatureFlags\Services;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Contracts\AudienceUserSearch;
use Illuminate\Support\Facades\DB;

final class DatabaseAudienceUserSearch implements AudienceUserSearch
{
    public function search(array $filters): array
    {
        $query = AdminUserProjection::query()->whereNotNull('user_id')->whereHas('user');
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(display_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }
        $query->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role));
        $query->when($filters['groupId'] ?? null, fn ($q, $group) => $q->whereIn('user_id',
            DB::table('feature_group_members')->select('user_id')->where('group_id', $group)));
        $page = $query->orderBy('name')->orderBy('user_id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $memberships = DB::table('feature_group_members')->whereIn('user_id', $page->pluck('user_id'))->get()->groupBy('user_id');

        return ['users' => $page->getCollection()->map(fn ($u) => [
            'id' => (string) $u->user_id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
            'groupIds' => $memberships->get($u->user_id, collect())->pluck('group_id')->all(),
        ])->all(), 'total' => $page->total(), 'page' => $page->currentPage(), 'lastPage' => $page->lastPage()];
    }
}
