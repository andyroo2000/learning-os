<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Support\CardSearchText;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CardSearchTextTest extends TestCase
{
    public function test_it_builds_search_text_from_card_content_and_structured_scalars(): void
    {
        $searchText = CardSearchText::fromContent(
            frontText: "  What\nis ATP?  ",
            backText: 'Cellular energy currency.',
            promptJson: [
                'type' => 'text',
                'content' => [
                    'term' => 'Adenosine triphosphate',
                    'cloze' => 1,
                ],
            ],
            answerJson: [
                'valid' => true,
                'hint' => null,
            ],
        );

        $this->assertSame(
            'What is ATP? Cellular energy currency. text Adenosine triphosphate 1 true',
            $searchText,
        );
    }

    public function test_it_rejects_blank_queries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card search query filter must not be blank when provided.');

        CardSearchText::normalizeQuery('   ');
    }

    public function test_it_escapes_like_wildcards_in_queries(): void
    {
        $this->assertSame('%100\\% recall\\_deck%', CardSearchText::likePattern('100% Recall_Deck'));
    }
}
