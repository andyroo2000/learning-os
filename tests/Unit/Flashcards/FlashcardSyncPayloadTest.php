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
    public function test_soft_deleted_deck_payload_serializes_deleted_at(): void
    {
        $deck = new Deck;
        $deck->setRawAttributes([
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'name' => 'Biology',
            'description' => 'Chapter 4',
            'created_at' => Carbon::parse('2026-05-27T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-27T09:15:00Z'),
            'deleted_at' => Carbon::parse('2026-05-27T09:16:00Z'),
        ], sync: true);

        $payload = DeckSyncPayload::fromDeck($deck);

        $expected = [
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
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

    public function test_deck_payload_uses_client_facing_resource_keys(): void
    {
        $deck = new Deck;
        $deck->setRawAttributes([
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => null,
            'name' => 'Biology',
            'description' => null,
            'created_at' => Carbon::parse('2026-05-27T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-27T09:15:00Z'),
            'deleted_at' => null,
        ], sync: true);

        $payload = DeckSyncPayload::fromDeck($deck);

        $this->assertSame([
            'id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => null,
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
            'deck_course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'study_status' => 'review',
            'due_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'introduced_at' => Carbon::parse('2026-06-01T14:15:00Z'),
            'failed_at' => Carbon::parse('2026-06-02T14:15:00Z'),
            'last_reviewed_at' => Carbon::parse('2026-06-03T14:15:00Z'),
            'created_at' => Carbon::parse('2026-05-28T10:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-28T10:15:00Z'),
            'deleted_at' => null,
        ], sync: true);

        $payload = CardSyncPayload::fromCard($card);

        $expected = [
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'study_status' => 'review',
            'due_at' => '2026-06-05T14:15:00.000000Z',
            'introduced_at' => '2026-06-01T14:15:00.000000Z',
            'failed_at' => '2026-06-02T14:15:00.000000Z',
            'last_reviewed_at' => '2026-06-03T14:15:00.000000Z',
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
            'deck_course_id' => null,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'study_status' => 'new',
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
            'created_at' => Carbon::parse('2026-05-28T10:14:00Z'),
            'updated_at' => Carbon::parse('2026-05-28T10:15:00Z'),
            'deleted_at' => Carbon::parse('2026-05-28T10:20:00Z'),
        ], sync: true);

        $payload = CardSyncPayload::fromCard($card);

        $this->assertSame([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'course_id' => null,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'study_status' => 'new',
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
            'created_at' => '2026-05-28T10:14:00.000000Z',
            'updated_at' => '2026-05-28T10:15:00.000000Z',
            'deleted_at' => '2026-05-28T10:20:00.000000Z',
        ], $payload);
    }
}
