<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\PresentStudyMilestonesAction;
use App\Domain\Study\Actions\ReconcileStudyMilestonesAction;
use App\Domain\Study\Enums\StudyMilestoneKey;
use App\Domain\Study\Models\StudyMilestone;
use App\Domain\Study\Models\StudyMilestoneProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class StudyMilestoneActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_reconciliation_backfills_qualified_history_as_presented(): void
    {
        $user = User::factory()->create();
        $now = Carbon::parse('2026-08-25T20:00:00Z');

        $milestones = app(ReconcileStudyMilestonesAction::class)->handle($user->id, 500, $now);

        $this->assertSame(
            [StudyMilestoneKey::Burned500, StudyMilestoneKey::Burned100],
            $milestones->pluck('milestone_key')->all(),
        );
        $this->assertTrue($milestones->every(
            fn (StudyMilestone $milestone): bool => $milestone->presented_at?->equalTo($now) === true,
        ));
        $this->assertTrue(
            StudyMilestoneProfile::query()->findOrFail($user->id)->initialized_at->equalTo($now),
        );
    }

    public function test_later_thresholds_are_pending_idempotent_and_presentation_is_permanent(): void
    {
        $user = User::factory()->create();
        $reconcile = app(ReconcileStudyMilestonesAction::class);
        $reconcile->handle($user->id, 0, Carbon::parse('2026-08-25T20:00:00Z'));

        $earnedAt = Carbon::parse('2026-08-25T21:00:00Z');
        $first = $reconcile->handle($user->id, 500, $earnedAt);
        $second = $reconcile->handle($user->id, 500, $earnedAt->copy()->addMinute());

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(2, StudyMilestone::query()->where('user_id', $user->id)->count());
        $this->assertTrue($second->every(
            static fn (StudyMilestone $milestone): bool => $milestone->presented_at === null,
        ));

        app(PresentStudyMilestonesAction::class)->handle($user->id, [
            StudyMilestoneKey::Burned100,
            StudyMilestoneKey::Burned500,
        ]);
        app(PresentStudyMilestonesAction::class)->handle($user->id, [
            StudyMilestoneKey::Burned100,
        ]);

        $afterRegression = $reconcile->handle($user->id, 0);
        $this->assertCount(2, $afterRegression);
        $this->assertTrue($afterRegression->every(
            static fn (StudyMilestone $milestone): bool => $milestone->presented_at !== null,
        ));
    }

    public function test_unpresented_award_is_retracted_when_an_undo_drops_below_threshold(): void
    {
        $user = User::factory()->create();
        $reconcile = app(ReconcileStudyMilestonesAction::class);
        $reconcile->handle($user->id, 99);
        $reconcile->handle($user->id, 100);

        $this->assertDatabaseHas('study_milestones', [
            'user_id' => $user->id,
            'milestone_key' => StudyMilestoneKey::Burned100->value,
            'presented_at' => null,
        ]);

        $milestones = $reconcile->handle($user->id, 99);

        $this->assertCount(0, $milestones);
        $this->assertDatabaseMissing('study_milestones', [
            'user_id' => $user->id,
            'milestone_key' => StudyMilestoneKey::Burned100->value,
        ]);
    }

    public function test_reconciliation_is_isolated_by_user_and_cascades_on_account_deletion(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $reconcile = app(ReconcileStudyMilestonesAction::class);
        $reconcile->handle($user->id, 0);
        $reconcile->handle($other->id, 100);
        $reconcile->handle($user->id, 100);

        $this->assertSame(1, StudyMilestone::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, StudyMilestone::query()->where('user_id', $other->id)->count());

        $user->delete();

        $this->assertDatabaseMissing('study_milestone_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('study_milestones', ['user_id' => $user->id]);
        $this->assertDatabaseHas('study_milestones', ['user_id' => $other->id]);
    }

    public function test_direct_callers_reject_invalid_boundaries(): void
    {
        $reconcile = app(ReconcileStudyMilestonesAction::class);

        $this->assertThrows(
            fn () => $reconcile->handle(0, 0),
            InvalidArgumentException::class,
        );
        $this->assertThrows(
            fn () => $reconcile->handle(1, -1),
            InvalidArgumentException::class,
        );
        $this->assertThrows(
            fn () => app(PresentStudyMilestonesAction::class)->handle(1, []),
            InvalidArgumentException::class,
        );
    }

    public function test_server_owned_milestone_fields_are_not_mass_assignable(): void
    {
        $this->assertThrows(
            fn () => (new StudyMilestone)->fill([
                'user_id' => 1,
                'milestone_key' => StudyMilestoneKey::Burned100->value,
                'earned_at' => now(),
                'presented_at' => now(),
            ]),
            MassAssignmentException::class,
        );
        $this->assertThrows(
            fn () => (new StudyMilestoneProfile)->fill([
                'user_id' => 1,
                'initialized_at' => now(),
            ]),
            MassAssignmentException::class,
        );
    }
}
