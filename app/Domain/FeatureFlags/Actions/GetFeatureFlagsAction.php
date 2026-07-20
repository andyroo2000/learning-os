<?php

namespace App\Domain\FeatureFlags\Actions;

use App\Domain\FeatureFlags\Models\FeatureFlag;
use Illuminate\Database\UniqueConstraintViolationException;

class GetFeatureFlagsAction
{
    public function handle(): FeatureFlag
    {
        $featureFlags = FeatureFlag::query()
            ->orderByDesc('updatedAt')
            ->orderBy('id')
            ->first();

        if ($featureFlags !== null) {
            return $featureFlags;
        }

        $featureFlags = new FeatureFlag([
            'dialoguesEnabled' => true,
            'scriptsEnabled' => true,
            'audioCourseEnabled' => true,
            'flashcardsEnabled' => true,
        ]);
        $featureFlags->id = FeatureFlag::DEFAULT_ID;

        try {
            $featureFlags->save();

            return $featureFlags;
        } catch (UniqueConstraintViolationException) {
            // Concurrent first reads race on one stable primary key; return the winner's row.
            return FeatureFlag::query()->findOrFail(FeatureFlag::DEFAULT_ID);
        }
    }
}
