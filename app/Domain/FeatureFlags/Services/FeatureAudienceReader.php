<?php

namespace App\Domain\FeatureFlags\Services;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FeatureAudienceReader
{
    public function users(array $ids): array
    {
        return AdminUserProjection::query()->whereIn('user_id', $ids)
            ->orderBy('name')->orderBy('user_id')->get()
            ->map(fn ($user) => [
                'id' => (string) $user->user_id, 'name' => $user->name,
                'email' => $user->email, 'role' => $user->role,
            ])->all();
    }

    public function audience(string $feature): array
    {
        $row = DB::table('feature_audiences')->where('feature', $feature)->first();
        abort_if($row === null, 404);
        $userIds = DB::table('feature_audience_users')->where('feature', $feature)->pluck('user_id')->all();
        $groupIds = DB::table('feature_audience_groups')->where('feature', $feature)->pluck('group_id')->all();

        return ['feature' => $feature, 'version' => (int) $row->version,
            'users' => $this->users($userIds), 'groupIds' => $groupIds];
    }

    public function group(string $id): array
    {
        $row = DB::table('feature_access_groups')->where('id', $id)->first();
        abort_if($row === null, 404);

        return $this->serializeGroups(collect([$row]))[0];
    }

    public function groups(): array
    {
        return $this->serializeGroups(DB::table('feature_access_groups')->orderBy('name')->orderBy('id')->get());
    }

    private function serializeGroups(Collection $rows): array
    {
        $ids = $rows->pluck('id');
        $members = DB::table('feature_group_members')->whereIn('group_id', $ids)->orderBy('user_id')->get();
        $users = collect($this->users($members->pluck('user_id')->unique()->all()))->keyBy('id');
        $byGroup = $members->groupBy('group_id');
        $features = DB::table('feature_audience_groups')->whereIn('group_id', $ids)->orderBy('feature')->get()->groupBy('group_id');

        return $rows->map(fn ($row) => [
            'id' => $row->id, 'name' => $row->name, 'version' => (int) $row->version,
            'users' => $byGroup->get($row->id, collect())->map(fn ($member) => $users->get($member->user_id))->filter()->values()->all(),
            'features' => $features->get($row->id, collect())->pluck('feature')->all(),
        ])->all();
    }
}
