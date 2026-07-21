<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Services\ContentOpenAiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContentOpenAiClientTest extends TestCase
{
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
