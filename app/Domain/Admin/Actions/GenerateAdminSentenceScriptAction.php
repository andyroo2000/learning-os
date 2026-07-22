<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\GenerateAdminSentenceScriptData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Results\AdminSentenceScriptResult;
use App\Domain\Admin\Services\AdminSentenceScriptGenerator;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Str;
use Throwable;

final readonly class GenerateAdminSentenceScriptAction
{
    public function __construct(private AdminSentenceScriptGenerator $generator) {}

    /** @return array{test: AdminSentenceScriptTest, result: AdminSentenceScriptResult} */
    public function handle(
        string $actorConvoLabUserId,
        GenerateAdminSentenceScriptData $data,
    ): array {
        $actorConvoLabUserId = ConvoLabUserId::normalize($actorConvoLabUserId);

        try {
            $result = $this->generator->generate($data);
        } catch (Throwable $exception) {
            throw AdminMutationException::sentenceScriptProviderUnavailable($exception);
        }

        $test = AdminSentenceScriptTest::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'actor_convolab_user_id' => $actorConvoLabUserId,
            'sentence' => $data->sentence,
            'translation' => $result->translation,
            'target_language' => $data->targetLanguage,
            'native_language' => $data->nativeLanguage,
            'jlpt_level' => $data->jlptLevel,
            'l1_voice_id' => $data->l1VoiceId,
            'l2_voice_id' => $data->l2VoiceId,
            'prompt_template' => $result->resolvedPrompt,
            'units_json' => $result->units,
            'raw_response' => $result->rawResponse,
            'estimated_duration_secs' => $result->estimatedDurationSeconds,
            'parse_error' => $result->parseError,
            'created_at' => now(),
        ]);

        return ['test' => $test, 'result' => $result];
    }
}
