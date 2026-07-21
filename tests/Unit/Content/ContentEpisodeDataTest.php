<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Data\UpdateContentEpisodeData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentEpisodeDataTest extends TestCase
{
    public function test_create_data_normalizes_valid_direct_input(): void
    {
        $data = CreateContentEpisodeData::fromInput(
            userId: 1,
            convoLabUserId: ' C358732A-2CD0-4B18-9CCE-C474297863F9 ',
            title: ' Episode ',
            sourceText: ' Source ',
            targetLanguage: 'ja',
            nativeLanguage: 'en',
        );

        $this->assertSame('c358732a-2cd0-4b18-9cce-c474297863f9', $data->convoLabUserId);
        $this->assertSame('Episode', $data->title);
        $this->assertSame('Source', $data->sourceText);
        $this->assertSame('medium', $data->audioSpeed);
        $this->assertNull($data->jlptLevel);
        $this->assertTrue($data->autoGenerateAudio);
    }

    #[DataProvider('invalidCreateInputProvider')]
    public function test_create_data_rejects_invalid_direct_input(array $overrides): void
    {
        $input = [
            'userId' => 1,
            'convoLabUserId' => 'c358732a-2cd0-4b18-9cce-c474297863f9',
            'title' => 'Episode',
            'sourceText' => 'Source',
            'targetLanguage' => 'ja',
            'nativeLanguage' => 'en',
            'audioSpeed' => 'medium',
            'jlptLevel' => null,
            'autoGenerateAudio' => true,
            ...$overrides,
        ];

        $this->expectException(InvalidArgumentException::class);
        CreateContentEpisodeData::fromInput(...$input);
    }

    public static function invalidCreateInputProvider(): array
    {
        return [
            'non-positive user' => [['userId' => 0]],
            'malformed source user' => [['convoLabUserId' => 'bad-id']],
            'blank title' => [['title' => ' ']],
            'long title' => [['title' => str_repeat('a', 256)]],
            'blank source' => [['sourceText' => ' ']],
            'unsupported target language' => [['targetLanguage' => 'fr']],
            'unsupported native language' => [['nativeLanguage' => 'ja']],
            'blank audio speed' => [['audioSpeed' => ' ']],
            'unsupported audio speed' => [['audioSpeed' => 'fast']],
            'invalid JLPT level' => [['jlptLevel' => 'N0']],
        ];
    }

    public function test_update_data_preserves_sparse_presence(): void
    {
        $title = UpdateContentEpisodeData::fromInput(['title' => ' Updated ']);
        $this->assertTrue($title->hasTitle);
        $this->assertSame('Updated', $title->title);
        $this->assertFalse($title->hasStatus);
        $this->assertNull($title->status);

        $empty = UpdateContentEpisodeData::fromInput([]);
        $this->assertFalse($empty->hasTitle);
        $this->assertFalse($empty->hasStatus);
    }

    #[DataProvider('invalidUpdateInputProvider')]
    public function test_update_data_rejects_invalid_present_fields(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        UpdateContentEpisodeData::fromInput($input);
    }

    public static function invalidUpdateInputProvider(): array
    {
        return [
            'null title' => [['title' => null]],
            'blank title' => [['title' => ' ']],
            'array title' => [['title' => []]],
            'null status' => [['status' => null]],
            'blank status' => [['status' => ' ']],
            'long status' => [['status' => str_repeat('a', 33)]],
            'unknown status' => [['status' => 'completed']],
        ];
    }
}
