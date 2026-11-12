<?php

namespace App\Domain\Journal\Services;

use App\Domain\Journal\Support\JournalFeedback;
use App\Domain\Journal\Support\JournalFeedbackSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class GenerateJournalFeedback
{
    public function handle(string $body, array $context = []): array
    {
        try {
            Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:20000', 'not_regex:/\x00/']])->validate();

            return JournalFeedback::validated($body, $this->decode($this->request($body, $context)));
        } catch (ValidationException $exception) {
            throw new RuntimeException('Journal feedback did not match the required format.', previous: $exception);
        }
    }

    private function request(string $body, array $context): Response
    {
        $key = trim((string) config('services.openai.api_key'));
        if ($key === '') {
            throw new RuntimeException('Journal feedback is not configured.');
        }
        try {
            return Http::baseUrl((string) config('services.openai.base_url'))->withToken($key)
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(75)
                ->post('/responses', $this->payload($body, $context));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Journal feedback is temporarily unavailable.', previous: $exception);
        }
    }

    private function responseText(Response $response): string
    {
        if (! $response->successful() || $response->json('status') !== 'completed') {
            throw new RuntimeException('Journal feedback did not complete. Please try again.');
        }
        $content = collect($response->json('output', []))->where('type', 'message')->pluck('content')->flatten(1);
        if ($content->contains('type', 'refusal')) {
            throw new RuntimeException('Feedback could not be generated for this entry.');
        }

        return $content->where('type', 'output_text')->pluck('text')->implode('');
    }

    private function decode(Response $response): array
    {
        $text = $this->responseText($response);
        try {
            $output = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Journal feedback was not valid JSON.', previous: $exception);
        }
        if (! is_array($output)) {
            throw new RuntimeException('Journal feedback was not an object.');
        }

        return $output;
    }

    private function payload(string $body, array $context): array
    {
        return [
            'model' => config('services.openai.content_model'),
            'reasoning' => ['effort' => config('services.openai.content_reasoning_effort')],
            'store' => false,
            'max_output_tokens' => 16000,
            'input' => [
                ['role' => 'system', 'content' => File::get(resource_path('prompts/journal/feedback.txt'))],
                ['role' => 'user', 'content' => json_encode(['body' => $body, ...($context === [] ? [] : ['context' => $context])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
            'text' => ['format' => [
                'type' => 'json_schema', 'name' => 'journal_feedback', 'strict' => true,
                'schema' => JournalFeedbackSchema::definition(),
            ]],
        ];
    }
}
