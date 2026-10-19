<?php

namespace Tests\Feature\Study;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudyActivitySummaryAnalyticsMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_zero_days_update_after_create_move_category_change_and_delete(): void
    {
        $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T16:00:00Z'));
        $this->assertWeek('2026-10-12', 0, 'review');
        $this->assertWeek('2026-10-19', 0, 'immerse');
        $session = [
            'clientSessionId' => '018f22d2-6d38-7000-8000-000000000501',
            'activity' => 'card_review', 'source' => 'manual', 'origin' => 'ios',
            'startedAt' => '2026-10-12T12:00:00Z', 'endedAt' => '2026-10-12T12:10:00Z', 'durationMs' => 600_000,
        ];
        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => [$session]])->assertOk();
        $this->assertWeek('2026-10-12', 600_000, 'review');
        $session = array_merge($session, [
            'activity' => 'reading', 'startedAt' => '2026-10-19T12:00:00Z',
            'endedAt' => '2026-10-19T12:05:00Z', 'durationMs' => 300_000,
        ]);
        $this->postJson('/api/study/activity-sessions/batch', ['sessions' => [$session]])->assertOk();
        $this->assertWeek('2026-10-12', 0, 'review');
        $this->assertWeek('2026-10-19', 300_000, 'immerse');
        $this->deleteJson('/api/study/activity-sessions/'.$session['clientSessionId'])->assertNoContent();
        $this->assertWeek('2026-10-19', 0, 'immerse');
    }

    private function assertWeek(string $anchor, int $duration, string $category): void
    {
        $this->getJson('/api/study/activity-analytics?timezone=UTC&weekStartsOn=2&ranges[]=week&anchorDate='.$anchor)
            ->assertOk()->assertJsonCount(1, 'ranges')
            ->assertJsonPath('ranges.0.totalMs', $duration)->assertJsonPath('ranges.0.categories.'.$category, $duration);
    }
}
