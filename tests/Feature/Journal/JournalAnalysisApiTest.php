<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalEntry;
use App\Jobs\AnalyzeJournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JournalAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_queues_one_snapshot_and_polling_never_leaks_partial_output(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id;
        $this->getJson($url.'/analysis')->assertOk()->assertJsonPath('data', null);
        $first = $this->postJson($url.'/submit', ['expectedVersion' => 1])->assertAccepted()->json('data.id');
        $this->postJson($url.'/submit', ['expectedVersion' => 1])->assertAccepted()->assertJsonPath('data.id', $first);
        Queue::assertPushed(AnalyzeJournalEntry::class, fn ($job) => $job->analysisId === $first);
        $this->assertDatabaseCount('journal_analyses', 1);
        $analysis = app(StartJournalAnalysis::class)->handle($user->id, $entry->id, 1);
        $analysis->status = JournalAnalysisStatus::Processing;
        $analysis->result = ['partial' => 'must stay private'];
        $analysis->save();
        $this->getJson($url.'/analysis')->assertOk()->assertJsonPath('data.result', null)
            ->assertJsonPath('entry.version', 1)->assertJsonMissingPath('data.attempt_id');
        $entry->body = '違う文章。';
        $entry->save();
        $this->getJson($url.'/analysis')->assertOk()->assertJsonPath('data', null);
    }

    public function test_analysis_endpoints_require_authentication_and_ownership(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id;
        $this->postJson($url.'/submit', ['expectedVersion' => 1])->assertUnauthorized();
        $this->getJson($url.'/analysis')->assertUnauthorized();
        $this->signIn();
        $this->postJson($url.'/submit', ['expectedVersion' => 1])->assertNotFound();
        $this->getJson($url.'/analysis')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_submission_has_a_separate_generation_rate_limit(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson($url.'/submit', ['expectedVersion' => 1])->assertAccepted();
        }
        $this->postJson($url.'/submit', ['expectedVersion' => 1])->assertTooManyRequests();
        $this->getJson($url.'/analysis')->assertOk();
    }
}
