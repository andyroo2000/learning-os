<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiStudyCardGeneratorTest extends TestCase
{
    public function test_it_reads_nested_output_content_when_output_text_is_absent(): void
    {
        config()->set('services.openai.api_key', 'test-key');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'content' => [
                            ['type' => 'output_text', 'text' => '{"targetWord":"会社"}'],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertSame(
            '{"targetWord":"会社"}',
            app(OpenAiStudyCardGenerator::class)->generateJson('system', 'prompt'),
        );
    }

    public function test_it_rejects_missing_credentials_before_making_a_request(): void
    {
        config()->set('services.openai.api_key', null);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY is required');

        try {
            app(OpenAiStudyCardGenerator::class)->generateJson('system', 'prompt');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_hides_rejected_provider_credentials(): void
    {
        config()->set('services.openai.api_key', 'bad-key');
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'Incorrect API key: bad-key'],
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider rejected the configured credentials');

        app(OpenAiStudyCardGenerator::class)->generateJson('system', 'prompt');
    }
}
