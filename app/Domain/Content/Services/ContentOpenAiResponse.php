<?php

namespace App\Domain\Content\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

final readonly class ContentOpenAiResponse
{
    public function __construct(
        private Response $response,
        private string $contentLabel,
    ) {}

    public function text(): string
    {
        $text = $this->nonBlankText($this->response->json('output_text'));
        if ($text !== null) {
            return $text;
        }

        $text = $this->nestedText();
        if ($text !== null) {
            return $text;
        }

        throw new RuntimeException("OpenAI returned no {$this->contentLabel} content.");
    }

    private function nestedText(): ?string
    {
        $output = $this->response->json('output');
        if (! is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            $text = $this->contentItemText($item);
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function contentItemText(mixed $item): ?string
    {
        $contentItems = is_array($item) ? ($item['content'] ?? null) : null;
        if (! is_array($contentItems)) {
            return null;
        }

        foreach ($contentItems as $content) {
            $text = is_array($content) ? $this->nonBlankText($content['text'] ?? null) : null;
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function nonBlankText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
