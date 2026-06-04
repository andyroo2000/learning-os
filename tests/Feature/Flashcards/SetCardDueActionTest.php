<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\SetCardDueAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SetCardDueActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_a_custom_due_date_and_records_a_sync_entry(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::New,
            'new_queue_position' => 7,
        ]);

        $result = app(SetCardDueAction::class)->handle(
            card: $card,
            mode: 'custom_date',
            dueAt: '2026-06-05T14:15:00Z',
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Review, $result->card->study_status);
        $this->assertNull($result->card->new_queue_position);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $result->card->due_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame($card->id, $entry->resource_id);
        $this->assertSame('review', $entry->payload['study_status']);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $entry->payload['due_at']);
        $this->assertNull($entry->payload['new_queue_position']);
    }

    public function test_tomorrow_mode_sets_due_at_to_9am_in_the_requested_timezone(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Suspended,
            'due_at' => null,
        ]);

        $result = app(SetCardDueAction::class)->handle(
            card: $card,
            mode: 'tomorrow',
            timeZone: 'America/New_York',
            now: Carbon::parse('2026-06-04T22:00:00Z'),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardStudyStatus::Review, $result->card->study_status);
        $this->assertSame('2026-06-05T13:00:00.000000Z', $result->card->due_at?->toJSON());
    }

    public function test_now_mode_uses_the_current_time(): void
    {
        $now = Carbon::parse('2026-06-04T12:00:00Z');
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T12:00:00Z',
        ]);

        $result = app(SetCardDueAction::class)->handle(
            card: $card,
            mode: 'now',
            now: $now,
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame($now->toJSON(), $result->card->due_at?->toJSON());
        $this->assertSame(CardStudyStatus::Review, $result->card->study_status);
    }

    public function test_it_is_idempotent_when_due_state_is_unchanged(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $dueAt = Carbon::parse('2026-06-05T14:15:00Z');
        $card = $this->cardFor($this->signIn(), [
            'study_status' => CardStudyStatus::Review,
            'due_at' => $dueAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $result = app(SetCardDueAction::class)->handle(
            card: $card,
            mode: 'custom_date',
            dueAt: '2026-06-05T14:15:00Z',
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame($timestamp->toJSON(), $card->refresh()->updated_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_invalid_modes_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Set-due mode must be one of: now, tomorrow, custom_date.');

        app(SetCardDueAction::class)->handle(
            card: $this->cardFor($this->signIn()),
            mode: 'later',
        );
    }

    public function test_it_rejects_tomorrow_without_a_valid_timezone_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('time_zone must be a valid IANA timezone for tomorrow.');

        app(SetCardDueAction::class)->handle(
            card: $this->cardFor($this->signIn()),
            mode: 'tomorrow',
        );
    }

    public function test_it_rejects_invalid_custom_due_dates_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('due_at must be a valid ISO-8601 datetime for custom_date.');

        app(SetCardDueAction::class)->handle(
            card: $this->cardFor($this->signIn()),
            mode: 'custom_date',
            dueAt: 'tomorrow',
        );
    }

    public function test_it_rejects_custom_due_dates_more_than_ten_years_out_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('due_at must be within 10 years.');

        app(SetCardDueAction::class)->handle(
            card: $this->cardFor($this->signIn()),
            mode: 'custom_date',
            dueAt: '2037-06-04T12:00:00Z',
            now: Carbon::parse('2026-06-04T12:00:00Z'),
        );
    }
}
