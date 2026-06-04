<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Flashcards\Sync\DeckSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class FlashcardSyncPayloadTest extends TestCase
{
    public function test_deck_payload_uses_client_facing_resource_keys(): void
    {
        $deck = new Deck;
        $deck->setRawAttributes([
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'name' => 'Biology',
            'description' => 'Chapter 4',
            'created_at' => Carbon::parse('2026-05-27T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-27T09:15:00Z'),
            'deleted_at' => Carbon::parse('2026-05-27T09:16:00Z'),
        ], sync: true);

        $payload = DeckSyncPayload::fromDeck($deck);

        $expected = [
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'name' => 'Biology',
            'description' => 'Chapter 4',
            'created_at' => '2026-05-27T09:14:00.000000Z',
            'updated_at' => '2026-05-27T09:15:00.000000Z',
            'deleted_at' => '2026-05-27T09:16:00.000000Z',
        ];

        $this->assertSame('flashcards', DeckSyncPayload::DOMAIN);
        $this->assertSame('deck', DeckSyncPayload::RESOURCE_TYPE);
        $this->assertSame($expected, $payload);
    }

    public function test_live_deck_payload_serializes_deleted_at_as_null(): void
    {
        $deck = new Deck;
        $deck->setRawAttributes([
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'name' => 'Biology',
            'description' => null,
            'created_at' => Carbon::parse('2026-05-27T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-27T09:15:00Z'),
            'deleted_at' => null,
        ], sync: true);

        $payload = DeckSyncPayload::fromDeck($deck);

        $this->assertSame([
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'name' => 'Biology',
            'description' => null,
            'created_at' => '2026-05-27T09:14:00.000000Z',
            'updated_at' => '2026-05-27T09:15:00.000000Z',
            'deleted_at' => null,
        ], $payload);
    }

    public function test_card_payload_uses_client_facing_resource_keys(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'created_at' => Carbon::parse('2026-05-28T10:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-28T10:15:00Z'),
            'deleted_at' => null,
        ], sync: true);

        $payload = CardSyncPayload::fromCard($card);

        $expected = [
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'created_at' => '2026-05-28T10:14:00.000000Z',
            'updated_at' => '2026-05-28T10:15:00.000000Z',
            'deleted_at' => null,
        ];

        $this->assertSame('flashcards', CardSyncPayload::DOMAIN);
        $this->assertSame('card', CardSyncPayload::RESOURCE_TYPE);
        $this->assertSame($expected, $payload);
        $this->assertArrayNotHasKey('media_assets', $payload);
    }

    public function test_soft_deleted_card_payload_serializes_deleted_at(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'created_at' => Carbon::parse('2026-05-28T10:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-28T10:15:00Z'),
            'deleted_at' => Carbon::parse('2026-05-28T10:20:00Z'),
        ], sync: true);

        $payload = CardSyncPayload::fromCard($card);

        $this->assertSame([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'created_at' => '2026-05-28T10:14:00.000000Z',
            'updated_at' => '2026-05-28T10:15:00.000000Z',
            'deleted_at' => '2026-05-28T10:20:00.000000Z',
        ], $payload);
    }
}
