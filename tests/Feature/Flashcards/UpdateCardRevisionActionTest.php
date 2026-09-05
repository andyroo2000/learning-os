<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Exceptions\CardContentRevisionConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateCardRevisionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_stale_expected_content_revision_before_mutating_the_locked_card(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'company',
        ]);
        DB::table('cards')->where('id', $card->id)->update(['content_revision' => 4]);

        try {
            app(UpdateCardAction::class)->handle(
                $card,
                UpdateCardData::fromInput(
                    frontText: '学校',
                    backText: 'school',
                    expectedContentRevision: 3,
                ),
            );

            $this->fail('Expected a stale card content revision to be rejected.');
        } catch (CardContentRevisionConflictException $e) {
            $this->assertSame($card->id, $e->card->id);
            $this->assertSame(4, $e->card->content_revision);
        }

        $card->refresh();
        $this->assertSame('会社', $card->front_text);
        $this->assertSame('company', $card->back_text);
        $this->assertSame(4, $card->content_revision);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_advances_the_expected_content_revision_only_for_a_real_content_update(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'company',
        ]);

        $updated = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '学校',
                backText: 'school',
                expectedContentRevision: 0,
            ),
        )->card->refresh();

        $this->assertSame(1, $updated->content_revision);

        $unchanged = app(UpdateCardAction::class)->handle(
            $updated,
            UpdateCardData::fromInput(
                frontText: '学校',
                backText: 'school',
                expectedContentRevision: 1,
            ),
        );

        $this->assertFalse($unchanged->wasUpdated);
        $this->assertSame(1, $unchanged->card->content_revision);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_rejects_a_negative_expected_content_revision_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected card content revision must be zero or greater.');

        UpdateCardData::fromInput(
            frontText: '会社',
            backText: 'company',
            expectedContentRevision: -1,
        );
    }
}
