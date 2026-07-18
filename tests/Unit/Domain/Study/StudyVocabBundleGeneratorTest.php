<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Services\OpenAiStudyCardGenerator;
use App\Domain\Study\Services\StudyLearnerContextBuilder;
use App\Domain\Study\Services\StudyVocabBundleGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudyVocabBundleGeneratorTest extends TestCase
{
    public function test_it_parses_code_fenced_provider_json_into_eleven_variants(): void
    {
        $generator = $this->generatorReturning(
            "```json\n".json_encode(self::validBundle(), JSON_THROW_ON_ERROR)."\n```",
        );

        $bundle = $generator->generate($this->group());

        $this->assertSame('会社', $bundle['targetWord']);
        $this->assertCount(3, $bundle['sentences']);
        $this->assertCount(StudyVocabBundleGenerator::DRAFT_COUNT, $bundle['variants']);
        $this->assertSame('この会社で働いています。', $bundle['sentences'][0]['sentenceJp']);
        $this->assertSame('sentence_audio_recognition', $bundle['variants'][0]['variantKind']->value);
        $this->assertSame('sentence_cloze', $bundle['variants'][10]['variantKind']->value);
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_it_rejects_malformed_provider_payloads(
        string $response,
        string $expectedMessage,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->generatorReturning($response)->generate($this->group());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidResponseProvider(): array
    {
        $missingMeaning = self::validBundle();
        unset($missingMeaning['targetMeaning']);
        $wrongTarget = self::validBundle();
        $wrongTarget['targetWord'] = '学校';
        $twoSentences = self::validBundle();
        array_pop($twoSentences['sentences']);
        $nonObjectSentence = self::validBundle();
        $nonObjectSentence['sentences'][0] = 'not an object';
        $missingReading = self::validBundle();
        unset($missingReading['sentences'][0]['sentenceReading']);
        $longNotes = self::validBundle();
        $longNotes['sentences'][0]['notes'] = str_repeat('a', 4001);

        return [
            'invalid json' => ['not json', 'Could not parse the generated study vocab bundle.'],
            'list root' => ['[]', 'Generated study vocab bundle must be an object.'],
            'missing required root field' => [
                json_encode($missingMeaning, JSON_THROW_ON_ERROR),
                'Generated study vocab bundle is missing targetMeaning.',
            ],
            'provider changes target word' => [
                json_encode($wrongTarget, JSON_THROW_ON_ERROR),
                'Generated study vocab bundle changed the requested target word.',
            ],
            'wrong sentence count' => [
                json_encode($twoSentences, JSON_THROW_ON_ERROR),
                'Generated study vocab bundle must include exactly three sentences.',
            ],
            'sentence is not an object' => [
                json_encode($nonObjectSentence, JSON_THROW_ON_ERROR),
                'Generated study vocab sentence must be an object.',
            ],
            'missing sentence reading' => [
                json_encode($missingReading, JSON_THROW_ON_ERROR),
                'Generated study vocab bundle is missing sentenceReading.',
            ],
            'oversized notes' => [
                json_encode($longNotes, JSON_THROW_ON_ERROR),
                'Generated study vocab bundle field notes is too long.',
            ],
        ];
    }

    private function generatorReturning(string $response): StudyVocabBundleGenerator
    {
        $openAi = $this->mock(OpenAiStudyCardGenerator::class);
        $openAi->shouldReceive('generateJson')->once()->andReturn($response);
        $learnerContext = $this->mock(StudyLearnerContextBuilder::class);
        $learnerContext->shouldNotReceive('build');

        return new StudyVocabBundleGenerator($openAi, $learnerContext);
    }

    private function group(): StudyVocabVariantGroup
    {
        $group = new StudyVocabVariantGroup;
        $group->user_id = 1;
        $group->target_word = '会社';
        $group->source_sentence = null;
        $group->source_context = null;
        $group->include_learner_context = false;

        return $group;
    }

    /** @return array<string, mixed> */
    private static function validBundle(): array
    {
        return [
            'targetWord' => '会社',
            'targetReading' => '会社[かいしゃ]',
            'targetMeaning' => 'company',
            'sentences' => [
                [
                    'sentenceJp' => 'この会社で働いています。',
                    'sentenceReading' => 'この会社[かいしゃ]で働[はたら]いています。',
                    'sentenceEn' => 'I work at this company.',
                    'clozeText' => 'この{{c1::会社}}で働いています。',
                    'clozeHint' => 'company',
                    'notes' => 'A common workplace phrase.',
                ],
                [
                    'sentenceJp' => '会社は駅の近くです。',
                    'sentenceReading' => '会社[かいしゃ]は駅[えき]の近[ちか]くです。',
                    'sentenceEn' => 'The company is near the station.',
                    'clozeText' => '{{c1::会社}}は駅の近くです。',
                    'clozeHint' => 'company',
                    'notes' => null,
                ],
                [
                    'sentenceJp' => '新しい会社を探しています。',
                    'sentenceReading' => '新[あたら]しい会社[かいしゃ]を探[さが]しています。',
                    'sentenceEn' => 'I am looking for a new company.',
                    'clozeText' => '新しい{{c1::会社}}を探しています。',
                    'clozeHint' => 'company',
                    'notes' => 'Used while job hunting.',
                ],
            ],
        ];
    }
}
