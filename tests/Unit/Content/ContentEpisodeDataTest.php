<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Data\UpdateContentEpisodeData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentEpisodeDataTest extends TestCase
{
    public function test_update_data_preserves_sparse_presence(): void
    {
        $title = UpdateContentEpisodeData::fromInput(['title' => ' Updated ']);
        $this->assertTrue($title->hasTitle);
        $this->assertSame('Updated', $title->title);
        $this->assertFalse($title->hasStatus);
        $this->assertNull($title->status);

        $status = UpdateContentEpisodeData::fromInput(['status' => ' ready ']);
        $this->assertFalse($status->hasTitle);
        $this->assertNull($status->title);
        $this->assertTrue($status->hasStatus);
        $this->assertSame('ready', $status->status);

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
