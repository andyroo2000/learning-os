<?php

namespace App\Domain\Journal\Services;

use App\Domain\Journal\Support\JournalClarificationResult;
use App\Domain\Journal\Support\JournalClarificationSchema;
use App\Support\Usage\ProviderUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class CheckJournalMeaning
{
    public function handle(string $body, array $answers = []): array
    {
        $input = Validator::make(compact('body', 'answers'), [
            'body' => ['required', 'string', 'max:20000', 'not_regex:/\x00/'],
            'answers' => ['present', 'array', 'list', 'max:100'],
            'answers.*' => ['required', 'array:question,answer'],
            'answers.*.question' => ['required', 'string', 'max:500', 'not_regex:/\x00/'],
            'answers.*.answer' => ['required', 'string', 'max:2000', 'not_regex:/\x00/'],
        ])->validate();
        try {
            return JournalClarificationResult::validated($this->decode($this->request($input)));
        } catch (ValidationException|JsonException $exception) {
            throw new RuntimeException('The meaning check returned an invalid response. Please try again.', previous: $exception);
        }
    }

    private function request(array $input): Response
    {
        $key = trim((string) config('services.openai.api_key'));
        if ($key === '') {
            throw new RuntimeException('Journal clarification is not configured.');
        }
        try {
            return ProviderUsage::request('openai', (string) config('journal.model'), [], fn () => Http::baseUrl((string) config('services.openai.base_url'))->withToken($key)
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(75)
                ->post('/responses', $this->payload($input)));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The meaning check is temporarily unavailable. Please try again.', previous: $exception);
        }
    }

    private function decode(Response $response): array
    {
        if (! $response->successful() || $response->json('status') !== 'completed') {
            throw new RuntimeException('The meaning check did not complete. Please try again.');
        }
        $content = collect($response->json('output', []))->where('type', 'message')->pluck('content')->flatten(1);
        if ($content->contains('type', 'refusal')) {
            throw new RuntimeException('The meaning check could not be completed for this entry.');
        }
        $decoded = json_decode($content->where('type', 'output_text')->pluck('text')->implode(''), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('The meaning check did not return an object.');
        }

        return $decoded;
    }

    private function payload(array $input): array
    {
        return [
            'model' => config('journal.model'),
            'reasoning' => ['effort' => config('journal.reasoning_effort')],
            'store' => false,
            'max_output_tokens' => 4000,
            'input' => [
                ['role' => 'system', 'content' => File::get(resource_path('prompts/journal/clarification.txt'))],
                ['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
            'text' => ['format' => [
                'type' => 'json_schema', 'name' => 'journal_clarification', 'strict' => true,
                'schema' => JournalClarificationSchema::definition(),
            ]],
        ];
    }
}
