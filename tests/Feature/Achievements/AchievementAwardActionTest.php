<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\CalculateAchievementMetricsAction;
use App\Domain\Achievements\Actions\ReconcileAchievementAwardsAction;
use App\Domain\Achievements\Actions\ResolveAchievementEarnedAtAction;
use App\Domain\Achievements\Models\AchievementAward;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AchievementAwardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_actions_reject_invalid_user_metric_and_threshold_values(): void
    {
        $reconcile = app(ReconcileAchievementAwardsAction::class);
        $resolver = app(ResolveAchievementEarnedAtAction::class);
        $metrics = app(CalculateAchievementMetricsAction::class);

        $this->assertThrows(
            fn () => $metrics->handle(0),
            InvalidArgumentException::class,
            'Achievement metric user ID must be positive.',
        );
        $this->assertThrows(
            fn () => $reconcile->handle(0, []),
            InvalidArgumentException::class,
            'Achievement award user ID must be positive.',
        );
        $this->assertThrows(
            fn () => $reconcile->handle(1, ['reviews.count' => -1]),
            InvalidArgumentException::class,
            'Achievement metric values must be non-negative.',
        );
        $this->assertThrows(
            fn () => $resolver->handle(1, 'reviews.count', 0),
            InvalidArgumentException::class,
            'Achievement award threshold must be positive.',
        );
        $this->assertThrows(
            fn () => $resolver->handle(1, 'unknown.metric', 1),
            InvalidArgumentException::class,
            'Unsupported achievement metric unknown.metric.',
        );
    }

    public function test_server_owned_award_fields_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $this->assertThrows(
            fn () => (new AchievementAward)->fill([
                'user_id' => $user->id,
                'achievement_id' => 'card-muncher.first-nibble',
                'earned_at' => now(),
            ]),
            MassAssignmentException::class,
        );
    }
}
