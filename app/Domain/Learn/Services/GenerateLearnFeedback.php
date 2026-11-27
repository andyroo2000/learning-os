<?php

namespace App\Domain\Learn\Services;

use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnTutorContract;
use App\Support\Usage\ProviderUsage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class GenerateLearnFeedback
{
    public function handle(array $lesson, array $state, array $input, string $tutor): array
    {
        $payload = $this->payload($lesson, $state, $input, $tutor);
        $text = $this->request($payload);
        try {
            return $this->decode($text, $input);
        } catch (RuntimeException|ValidationException|JsonException $error) {
            // Repair formatting once; never accept damaged anchors or silently rewrite learner text.
            $payload['input'][] = ['role' => 'user', 'content' => json_encode([
                'formatRepair' => 'Return the same feedback with valid exact-source annotations. Every kanji in message and EVERY reading field, including explanationReading and suggestionReading, needs bracket furigana. '.$error->getMessage(),
                'previousResponse' => $text,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

            return $this->decode($this->request($payload), $input);
        }
    }

    private function decode(string $text, array $input): array
    {
        $output = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($output)) {
            throw new RuntimeException('Tutor feedback must be an object.');
        }

        return LearnTutorContract::validate($input['text'], $input['action'], $output);
    }

    private function request(array $payload): string
    {
        $key = trim((string) config('services.openai.api_key'));
        if ($key === '') {
            throw new RuntimeException('The lesson tutor is not configured.');
        }
        $response = ProviderUsage::request('openai', (string) config('journal.model'), [], fn () => Http::baseUrl((string) config('services.openai.base_url'))
            ->withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout(75)
            ->post('/responses', $payload));
        if (! $response->successful() || $response->json('status') !== 'completed') {
            throw new RuntimeException('The tutor could not finish. Please try again.');
        }

        return collect($response->json('output', []))->where('type', 'message')->pluck('content')->flatten(1)
            ->where('type', 'output_text')->pluck('text')->implode('');
    }

    private function payload(array $lesson, array $state, array $input, string $tutor): array
    {
        $context = ['lesson' => $lesson['title'], 'reference' => $lesson['reference'],
            'teachingNotes' => $lesson['teachingNotes'], 'phase' => $state['phase'],
            'prompt' => app(LearnCatalog::class)->prompt($lesson, $state), 'tutor' => $tutor,
            'conversation' => array_slice($state['turns'], -12), 'action' => $input['action'], 'text' => $input['text']];

        return ['model' => config('journal.model'), 'reasoning' => ['effort' => config('journal.reasoning_effort')],
            'store' => false, 'max_output_tokens' => 10000,
            'input' => [['role' => 'system', 'content' => File::get(resource_path('prompts/learn/tutor.txt'))],
                ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'learn_feedback', 'strict' => true, 'schema' => LearnTutorContract::schema()]],
        ];
    }
}
