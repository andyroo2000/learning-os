<?php

namespace Tests\Feature\Admin;

use App\Domain\Journal\Support\JournalSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JournalAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/convolab/admin/journal-analytics';

    public function test_requires_an_admin_browser_session(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->asConvoLabBrowser(User::factory()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_empty_month_has_no_misleading_average_or_projection(): void
    {
        Carbon::setTestNow('2026-11-16 00:00:00 UTC');
        $this->asConvoLabAdminBrowser()->getJson(self::URL)->assertOk()
            ->assertJsonPath('month.activeUsers', 0)
            ->assertJsonPath('month.averageCostPerActiveUserUsd', null)
            ->assertJsonPath('month.projectedMonthlyCostPerActiveUserUsd', null)
            ->assertJsonCount(28, 'activity.buckets')->assertJsonCount(16, 'month.calendar')
            ->assertJsonPath('writers.total', 0);
    }

    public function test_counts_submissions_once_and_projects_monthly_costs_for_active_writers(): void
    {
        Carbon::setTestNow('2026-11-16 00:00:00 UTC');
        $writer = $this->writer();
        $root = (string) Str::ulid();
        JournalSubmission::record(['source_key' => 'entry:'.$root, 'user_id' => $writer->id, 'root_entry_id' => $root, 'kind' => 'original', 'characters' => 120, 'submitted_at' => '2026-11-02 12:00:00']);
        JournalSubmission::record(['source_key' => 'entry:'.$root, 'user_id' => $writer->id, 'root_entry_id' => $root, 'kind' => 'original', 'characters' => 999, 'submitted_at' => '2026-11-03 12:00:00']);
        JournalSubmission::record(['source_key' => 'reply:'.Str::ulid(), 'user_id' => $writer->id, 'root_entry_id' => $root, 'kind' => 'followup', 'characters' => 40, 'submitted_at' => '2026-11-03 12:00:00']);
        $this->cost($writer, 'openai', 1000000);
        $this->cost($writer, 'fish', 500000);
        $listener = $this->writer();
        $this->cost($listener, 'fish', 300000);
        $admin = User::factory()->create();
        $this->asConvoLabAdminBrowser($admin);
        $this->cost($admin, 'openai', 9000000);
        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('activity.summary.originals', 1)->assertJsonPath('activity.summary.followups', 1)
            ->assertJsonPath('activity.summary.activeUsers', 1)->assertJsonPath('activity.summary.averageEntryCharacters', 120)
            ->assertJsonPath('activity.summary.averageFollowupCharacters', 40)->assertJsonPath('activity.summary.averageFollowupsPerEntry', 1)
            ->assertJsonPath('month.activeUsers', 1)->assertJsonPath('month.averageCostPerActiveUserUsd', 1.5)
            ->assertJsonPath('month.projectedMonthlyCostPerActiveUserUsd', 3)
            ->assertJsonPath('month.inferenceUsd', 1)->assertJsonPath('month.ttsUsd', 0.8)
            ->assertJsonPath('writers.total', 2)->assertJsonPath('writers.data.0.dailyAverageUsd', 0.1);
        $this->assertStringNotContainsString('source_body', $response->getContent());
    }

    public function test_unpriced_requests_are_reported_and_future_usage_is_excluded(): void
    {
        Carbon::setTestNow('2026-11-16 00:00:00 UTC');
        $writer = $this->writer();
        $this->cost($writer, 'fish', null);
        $this->cost($writer, 'fish', 9000000, '2026-12-01 12:00:00');
        $this->asConvoLabAdminBrowser(User::factory()->create())->getJson(self::URL)->assertOk()
            ->assertJsonPath('month.unknownRequests', 1)->assertJsonPath('month.ttsUsd', 0)
            ->assertJsonPath('writers.data.0.unknownRequests', 1);
    }

    public function test_period_validation_and_zero_filled_bucket_sizes(): void
    {
        Carbon::setTestNow('2026-11-16 00:00:00 UTC');
        $this->asConvoLabAdminBrowser();
        foreach (['weekly' => 12, 'monthly' => 12, 'yearly' => 5] as $period => $count) {
            $this->getJson(self::URL.'?period='.$period)->assertOk()->assertJsonCount($count, 'activity.buckets');
        }
        $this->getJson(self::URL.'?period[]=daily')->assertUnprocessable();
        $this->getJson(self::URL.'?page[]=1')->assertUnprocessable();
        $this->getJson(self::URL.'?page=0')->assertUnprocessable();
    }

    private function writer(): User
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, (string) Str::uuid());

        return $user;
    }

    private function cost(User $user, string $provider, ?int $cost, string $time = '2026-11-05 12:00:00'): void
    {
        DB::table('ai_usage_events')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id,
            'resource_id' => (string) Str::ulid(), 'operation' => 'journal.audio', 'provider' => $provider,
            'model' => 'test', 'duration_ms' => 100, 'estimated_micro_usd' => $cost, 'created_at' => $time]);
    }
}
