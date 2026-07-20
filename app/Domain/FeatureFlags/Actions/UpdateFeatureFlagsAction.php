<?php

namespace App\Domain\FeatureFlags\Actions;

use App\Domain\FeatureFlags\Models\FeatureFlag;
use Illuminate\Support\Facades\DB;

class UpdateFeatureFlagsAction
{
    public function __construct(private GetFeatureFlagsAction $getFeatureFlags) {}

    /**
     * @param  array<string, bool>  $attributes
     */
    public function handle(array $attributes): FeatureFlag
    {
        // Legacy rows are immutable history; GetFeatureFlagsAction selects or materializes
        // the one row this write surface will continue updating.
        $featureFlags = $this->getFeatureFlags->handle();

        return DB::transaction(function () use ($featureFlags, $attributes): FeatureFlag {
            $lockedFeatureFlags = FeatureFlag::query()
                ->whereKey($featureFlags->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedFeatureFlags->fill($attributes);

            if ($lockedFeatureFlags->isDirty()) {
                $lockedFeatureFlags->save();
            }

            return $lockedFeatureFlags;
        });
    }
}
