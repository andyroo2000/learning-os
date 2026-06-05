<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Enums\CardType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CardTypeTest extends TestCase
{
    public function test_it_exposes_card_type_values(): void
    {
        $this->assertSame([
            'recognition',
            'production',
            'cloze',
        ], CardType::values());
    }

    public function test_it_normalizes_card_type_values(): void
    {
        $this->assertSame(CardType::Production, CardType::fromInput(' PRODUCTION '));
        $this->assertSame(CardType::Production, CardType::fromInput(CardType::Production));
    }

    public function test_it_rejects_blank_card_type_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must not be blank when provided.');

        CardType::fromInput('   ');
    }

    public function test_it_rejects_malformed_card_type_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must be one of: recognition, production, cloze.');

        CardType::fromInput('reverse');
    }

    public function test_it_normalizes_card_type_filters(): void
    {
        $this->assertSame(CardType::Production, CardType::fromFilter(' PRODUCTION '));
        $this->assertSame(CardType::Production, CardType::fromFilter(CardType::Production));
    }

    public function test_it_rejects_blank_card_type_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type filter must not be blank when provided.');

        CardType::fromFilter('   ');
    }

    public function test_it_rejects_malformed_card_type_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type filter must be one of: recognition, production, cloze.');

        CardType::fromFilter('reverse');
    }
}
