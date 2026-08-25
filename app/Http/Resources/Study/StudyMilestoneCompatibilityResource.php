<?php

namespace App\Http\Resources\Study;

use App\Domain\Study\Models\StudyMilestone;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyMilestoneCompatibilityResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{milestones: list<array{id: string, earnedAt: string|null, presentedAt: string|null}>, pendingMilestones: list<array{id: string, earnedAt: string|null, presentedAt: null}>} */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, StudyMilestone> $milestones */
        $milestones = $this->resource;

        $serialized = $milestones->map(fn (StudyMilestone $milestone): array => [
            'id' => $milestone->milestone_key->value,
            'earnedAt' => ConvoLabTimestamp::serialize($milestone->earned_at),
            'presentedAt' => ConvoLabTimestamp::serialize($milestone->presented_at),
        ])->values();

        return [
            'milestones' => $serialized->all(),
            'pendingMilestones' => $serialized
                ->filter(static fn (array $milestone): bool => $milestone['presentedAt'] === null)
                ->values()
                ->all(),
        ];
    }
}
