<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Jobs\AnalyzeJournalReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalReplyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_submission_is_idempotent_and_polling_hides_partial_results(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $id = strtolower((string) Str::ulid());
        $url = '/api/journal/entries/'.$entry->id.'/replies/'.strtoupper($id);
        $this->putJson($url, $input)->assertAccepted()->assertJsonPath('data.id', $id);
        $this->putJson($url, $input)->assertAccepted();
        $this->assertDatabaseCount('journal_replies', 1);
        Queue::assertPushed(AnalyzeJournalReply::class);
        $reply = JournalReply::findOrFail($id);
        $reply->result = ['private' => 'partial'];
        $reply->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.result', null)->assertJsonMissingPath('data.attempt_id');
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')->assertOk()
            ->assertJsonPath('data.pendingReplyId', $id)->assertJsonPath('data.replyRevision', 0);
        $this->putJson($url, [...$input, 'answer' => 'Different'])->assertConflict();
    }

    public function test_replies_are_private_and_cannot_be_attached_to_another_entry(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $id = strtolower((string) Str::ulid());
        $url = '/api/journal/entries/'.$entry->id.'/replies/'.$id;
        $other = JournalEntry::factory()->create(['user_id' => $entry->user_id]);
        $this->putJson('/api/journal/entries/'.$other->id.'/replies/'.$id, $input)->assertNotFound();
        $this->putJson($url, $input)->assertAccepted();
        $this->signIn();
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, $input)->assertNotFound();
        $this->postJson('/api/journal/entries/'.$entry->id.'/undo', $input)->assertNotFound();
    }

    public function test_undo_restores_the_parent_and_rejects_stale_or_pending_changes(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $parent = $this->completedReply($analysis);
        $child = $this->completedReply($analysis, $parent->id);
        $analysis->current_reply_id = $child->id;
        $analysis->reply_revision = 2;
        $analysis->save();
        $url = '/api/journal/entries/'.$entry->id;
        $this->getJson($url.'/analysis')->assertOk()->assertJsonPath('data.currentReply.id', $child->id);
        $undo = ['analysisId' => strtoupper($analysis->id), 'expectedRevision' => 2];
        $analysis->pending_reply_id = $child->id;
        $analysis->save();
        $this->postJson($url.'/undo', $undo)->assertConflict();
        $analysis->pending_reply_id = null;
        $analysis->save();
        $this->postJson($url.'/undo', $undo)->assertOk()
            ->assertJsonPath('data.currentReply.id', $parent->id)->assertJsonPath('data.replyRevision', 3);
        $this->postJson($url.'/undo', $undo)->assertConflict();
        $this->postJson($url.'/undo', [...$undo, 'expectedRevision' => 3])->assertOk()->assertJsonPath('data.currentReply', null);
        $this->assertDatabaseCount('journal_replies', 2);
        $this->assertSame($entry->body, $entry->fresh()->body);
    }

    public function test_invalid_and_outdated_answers_never_queue_generation(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $url = '/api/journal/entries/'.$entry->id.'/replies/'.strtolower((string) Str::ulid());
        $this->putJson($url, [...$input, 'answer' => '   '])->assertUnprocessable();
        $this->putJson($url, [...$input, 'expectedRevision' => 9])->assertConflict();
        $entry->body = '別の日。';
        $entry->save();
        $this->putJson($url, $input)->assertConflict();
        Queue::assertNothingPushed();
    }

    private function ready(): array
    {
        Queue::fake();
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create();
        $analysis = new JournalAnalysis;
        $analysis->entry_id = $entry->id;
        $analysis->source_version = 1;
        $analysis->source_body = $entry->body;
        $analysis->status = JournalAnalysisStatus::Ready;
        $analysis->result = JournalFeedbackFixture::result();
        $analysis->save();

        return [$entry, $analysis, ['analysisId' => $analysis->id, 'answer' => '友達と見た。', 'expectedRevision' => 0, 'replace' => false]];
    }

    private function completedReply(JournalAnalysis $analysis, ?string $parentId = null): JournalReply
    {
        $reply = new JournalReply;
        $reply->analysis_id = $analysis->id;
        $reply->parent_id = $parentId;
        $reply->base_revision = 0;
        $reply->replaces_answer = false;
        $reply->answer = '友達と見た。';
        $reply->status = JournalAnalysisStatus::Ready;
        $reply->result = JournalFeedbackFixture::result();
        $reply->save();

        return $reply;
    }
}
