<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Data\CreateContentAudioScriptData;
use App\Domain\Content\Data\UpdateContentAudioScriptData;
use App\Domain\Content\Support\ContentAudioScriptInput;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentAudioScriptInputTest extends TestCase
{
    public function test_create_data_accepts_the_source_boundary_and_default_voice(): void
    {
        $data = CreateContentAudioScriptData::fromInput(
            42,
            strtoupper((string) Str::uuid()),
            str_repeat('日', ContentAudioScriptInput::MAX_SOURCE_CHARACTERS),
            null,
        );

        $this->assertSame(ContentAudioScriptInput::MAX_SOURCE_CHARACTERS, mb_strlen($data->sourceText));
        $this->assertSame(ContentAudioScriptInput::DEFAULT_VOICE_ID, $data->voiceId);
        $this->assertSame(strtolower($data->convoLabUserId), $data->convoLabUserId);
    }

    #[DataProvider('invalidCreateProvider')]
    public function test_create_data_rejects_invalid_direct_input(string $sourceText, ?string $voiceId): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateContentAudioScriptData::fromInput(42, (string) Str::uuid(), $sourceText, $voiceId);
    }

    public static function invalidCreateProvider(): array
    {
        return [
            'blank' => ['   ', null],
            'non-Japanese' => ['English only', null],
            'over source boundary' => [str_repeat('日', ContentAudioScriptInput::MAX_SOURCE_CHARACTERS + 1), null],
            'unsupported voice' => ['日本語です。', 'ja-JP-Wavenet-C'],
        ];
    }

    public function test_update_data_accepts_empty_and_maximum_segment_lists(): void
    {
        $empty = $this->updateData([]);
        $maximum = $this->updateData(array_fill(
            0,
            ContentAudioScriptInput::MAX_SEGMENTS,
            $this->segment(),
        ));

        $this->assertSame([], $empty->segments);
        $this->assertCount(ContentAudioScriptInput::MAX_SEGMENTS, $maximum->segments);
    }

    #[DataProvider('invalidTitleProvider')]
    public function test_update_data_rejects_an_invalid_title_at_the_direct_boundary(string $title): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->updateData([], $title);
    }

    public static function invalidTitleProvider(): array
    {
        return [
            'blank' => ['   '],
            'overlong' => [str_repeat('題', ContentAudioScriptInput::MAX_TITLE_CHARACTERS + 1)],
        ];
    }

    #[DataProvider('invalidSegmentsProvider')]
    public function test_update_data_rejects_invalid_direct_segment_shapes(array $segments): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->updateData($segments);
    }

    public static function invalidSegmentsProvider(): array
    {
        return [
            'associative collection' => [['first' => ['text' => '文です。', 'translation' => 'Sentence.']]],
            'scalar segment' => [['not-an-object']],
            'too many segments' => [array_fill(0, ContentAudioScriptInput::MAX_SEGMENTS + 1, [
                'text' => '文です。',
                'translation' => 'Sentence.',
            ])],
            'non-Japanese text' => [[['text' => 'English', 'translation' => 'English']]],
            'missing translation' => [[['text' => '文です。']]],
        ];
    }

    private function updateData(array $segments, ?string $title = null): UpdateContentAudioScriptData
    {
        return UpdateContentAudioScriptData::fromInput(
            42,
            (string) Str::uuid(),
            (string) Str::uuid(),
            $title,
            null,
            $segments,
        );
    }

    private function segment(): array
    {
        return [
            'text' => '文です。',
            'reading' => '文[ぶん]です。',
            'translation' => 'It is a sentence.',
            'imagePrompt' => 'A written sentence.',
        ];
    }
}
