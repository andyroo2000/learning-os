<?php

namespace App\Domain\FeatureFlags\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Support\AudienceInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class GetEffectiveFeatureFlagsAction
{
    public function __construct(private GetFeatureFlagsAction $flags) {}

    public function enabled(int|string $userId, string $feature): bool
    {
        abort_unless(in_array($feature, AudienceInput::FEATURES, true), 404);

        return $this->flags->handle()->$feature || $this->isAdmin($userId)
            || DB::table('feature_audience_users')->where('user_id', $userId)->where('feature', $feature)->exists()
            || $this->groupGrants($userId)->where('audience.feature', $feature)->exists();
    }

    public function handle(int|string $userId): array
    {
        $global = $this->flags->handle()->only(AudienceInput::FEATURES);
        if (! in_array(false, $global, true)) {
            return $global;
        }
        if ($this->isAdmin($userId)) {
            return array_fill_keys(AudienceInput::FEATURES, true);
        }
        $direct = DB::table('feature_audience_users')->where('user_id', $userId)->pluck('feature')->all();
        $group = $this->groupGrants($userId)->pluck('audience.feature')->all();
        $result = [];
        foreach ($global as $feature => $enabled) {
            $result[$feature] = $enabled || in_array($feature, [...$direct, ...$group], true);
        }

        return $result;
    }

    private function isAdmin(int|string $userId): bool
    {
        return AdminUserProjection::where('user_id', $userId)->where('role', 'admin')->exists();
    }

    private function groupGrants(int|string $userId): Builder
    {
        return DB::table('feature_audience_groups as audience')
            ->join('feature_group_members as members', 'members.group_id', '=', 'audience.group_id')
            ->where('members.user_id', $userId);
    }
}
