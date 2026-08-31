<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Exceptions\CardContentRevisionConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardContentRevision;
use LogicException;
use PHPUnit\Framework\TestCase;

class CardContentRevisionTest extends TestCase
{
    public function test_it_rejects_a_stale_expected_revision_with_the_current_card(): void
    {
        $card = new Card;
        $card->setRawAttributes(['content_revision' => 4], sync: true);

        try {
            CardContentRevision::assertExpected($card, 3);
            $this->fail('Expected a stale content revision to be rejected.');
        } catch (CardContentRevisionConflictException $e) {
            $this->assertSame($card, $e->card);
        }
    }

    public function test_it_accepts_matching_and_omitted_expected_revisions(): void
    {
        $card = new Card;
        $card->setRawAttributes(['content_revision' => 4], sync: true);

        CardContentRevision::assertExpected($card, 4);
        CardContentRevision::assertExpected($card, null);

        $this->addToAssertionCount(1);
    }

    public function test_it_refuses_to_advance_beyond_the_platform_integer_limit(): void
    {
        $card = new Card;
        $card->setRawAttributes(['content_revision' => PHP_INT_MAX], sync: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card content revision cannot be advanced beyond the platform integer limit.');

        CardContentRevision::advance($card);
    }
}
