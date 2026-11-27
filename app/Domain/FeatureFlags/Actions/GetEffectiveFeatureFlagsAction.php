<?php

namespace App\Domain\FeatureFlags\Actions;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Support\AudienceInput;
use Illuminate\Support\Facades\DB;

final class GetEffectiveFeatureFlagsAction
{
    public function __construct(private GetFeatureFlagsAction $flags) {}

    public function handle(int|string $userId): array
    {
        $global = $this->flags->handle();
        $admin = AdminUserProjection::where('user_id', $userId)->where('role', 'admin')->exists();
        $direct = DB::table('feature_audience_users')->where('user_id', $userId)->pluck('feature')->all();
        $group = DB::table('feature_audience_groups as audience')
            ->join('feature_group_members as members', 'members.group_id', '=', 'audience.group_id')
            ->where('members.user_id', $userId)->pluck('audience.feature')->all();
        $result = [];
        foreach (AudienceInput::FEATURES as $feature) {
            $result[$feature] = $admin || $global->$feature || in_array($feature, [...$direct, ...$group], true);
        }

        return $result;
    }
}
