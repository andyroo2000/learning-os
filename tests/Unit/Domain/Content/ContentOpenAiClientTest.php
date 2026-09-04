<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Services\ContentOpenAiClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContentOpenAiClientTest extends TestCase
{
    public function test_it_prefers_the_trimmed_top_level_output_text(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            '*' => Http::response([
                'output_text' => '  Top-level response  ',
                'output' => [['content' => [['text' => 'Nested response']]]],
            ]),
        ]);

        $result = (new ContentOpenAiClient)->generateText('system', 'prompt');

        $this->assertSame('Top-level response', $result);
    }

    public function test_it_returns_the_first_non_blank_nested_output_text(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            '*' => Http::response([
                'output_text' => ' ',
                'output' => [
                    'invalid item',
                    ['content' => ['invalid content', ['text' => ' ']]],
                    ['content' => [['text' => '  Nested response  ']]],
                ],
            ]),
        ]);

        $result = (new ContentOpenAiClient)->generateJson('system', 'prompt');

        $this->assertSame('Nested response', $result);
    }

    public function test_it_rejects_a_successful_response_without_text(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output' => [['content' => [['type' => 'output_text']]]]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI returned no Dialogue content.');

        (new ContentOpenAiClient)->generateText('system', 'prompt', 'Dialogue');
    }

    #[DataProvider('contentLabelProvider')]
    public function test_missing_credentials_use_the_requested_content_label(?string $label, string $message): void
    {
        config()->set('services.openai.api_key', '');
        $client = new ContentOpenAiClient;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        if ($label === null) {
            $client->generateJson('system', 'prompt');
        } else {
            $client->generateJson('system', 'prompt', $label);
        }
    }

    public static function contentLabelProvider(): array
    {
        return [
            'course default remains compatible' => [null, 'OPENAI_API_KEY is required for Course generation.'],
            'dialogue errors identify the operation' => ['Dialogue', 'OPENAI_API_KEY is required for Dialogue generation.'],
        ];
    }
}
