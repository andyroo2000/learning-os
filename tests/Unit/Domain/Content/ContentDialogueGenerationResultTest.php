<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Results\ContentDialogueGenerationResult;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentDialogueGenerationResultTest extends TestCase
{
    public function test_it_accepts_the_exact_bounded_shape_and_falls_back_to_japanese_text_for_missing_reading(): void
    {
        $result = ContentDialogueGenerationResult::fromJson(
            json_encode($this->response(), JSON_THROW_ON_ERROR),
            $this->input(),
            'ja',
        );

        $this->assertSame('Travel Plans', $result->title);
        $this->assertCount(2, $result->sentences);
        $this->assertSame('旅行する。', $result->sentences[0]['reading']);
        $this->assertSame(['旅に出る。', '旅行に行く。'], $result->sentences[0]['variations']);

        $response = $this->response();
        $reordered = ['sentences' => $response['sentences'], 'title' => $response['title']];
        $this->assertSame(
            'Travel Plans',
            ContentDialogueGenerationResult::fromJson(
                json_encode($reordered, JSON_THROW_ON_ERROR),
                $this->input(),
                'ja',
            )->title,
        );
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_it_rejects_malformed_or_contract_breaking_provider_output(callable $mutate): void
    {
        $response = $this->response();
        $mutate($response);

        $this->expectException(InvalidArgumentException::class);
        ContentDialogueGenerationResult::fromJson(
            json_encode($response, JSON_THROW_ON_ERROR),
            $this->input(),
            'ja',
        );
    }

    public static function invalidResponseProvider(): array
    {
        return [
            'unknown top-level key' => [static function (array &$value): void {
                $value['extra'] = true;
            }],
            'wrong line count' => [static function (array &$value): void {
                array_pop($value['sentences']);
            }],
            'speaker does not alternate' => [static function (array &$value): void {
                $value['sentences'][1]['speaker'] = 'Aiko';
            }],
            'wrong variation count' => [static function (array &$value): void {
                $value['sentences'][0]['variations'] = ['one'];
            }],
            'unknown sentence key' => [static function (array &$value): void {
                $value['sentences'][0]['secret'] = true;
            }],
            'blank text' => [static function (array &$value): void {
                $value['sentences'][0]['text'] = ' ';
            }],
        ];
    }

    private function input(): GenerateContentDialogueData
    {
        return GenerateContentDialogueData::fromInput([
            'episodeId' => (string) Str::uuid(),
            'speakers' => [
                ['name' => 'Aiko [F]', 'voiceId' => 'voice-a', 'proficiency' => 'N4', 'tone' => 'casual', 'color' => null],
                ['name' => 'Ken [M]', 'voiceId' => 'voice-b', 'proficiency' => 'N3', 'tone' => 'polite', 'color' => null],
            ],
            'variationCount' => 2,
            'dialogueLength' => 2,
        ]);
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return [
            'title' => 'Travel Plans',
            'sentences' => [
                [
                    'speaker' => 'Aiko',
                    'text' => '旅行する。',
                    'translation' => 'Travel.',
                    'variations' => ['旅に出る。', '旅行に行く。'],
                ],
                [
                    'speaker' => 'Ken',
                    'text' => 'いいですね。',
                    'reading' => 'いいですね。',
                    'translation' => 'Sounds good.',
                    'variations' => ['賛成です。', '楽しそうです。'],
                ],
            ],
        ];
    }
}
