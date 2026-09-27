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
    public function test_it_parses_code_fenced_provider_json_into_fourteen_variants(): void
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
        $this->assertSame('sentence_production', $bundle['variants'][11]['variantKind']->value);
        $this->assertSame('production-text', $bundle['variants'][11]['creationKind']->value);
        $this->assertSame('I work at this company.', $bundle['variants'][11]['prompt']['cueText']);
        $this->assertSame('この会社で働いています。', $bundle['variants'][11]['answer']['expression']);
    }

    public function test_it_preserves_a_supplied_source_sentence_at_ordinal_zero(): void
    {
        $generator = $this->generatorReturning(
            json_encode(self::validBundle(), JSON_THROW_ON_ERROR),
        );

        $bundle = $generator->generate($this->group('この会社で働いています。'));

        $this->assertSame('この会社で働いています。', $bundle['sentences'][0]['sentenceJp']);
        $this->assertSame(
            'この会社で働いています。',
            $bundle['variants'][0]['answer']['expression'],
        );
    }

    public function test_it_builds_one_listening_card_and_one_reading_card_for_wanikani(): void
    {
        $bundle = $this->generatorReturning(
            json_encode(self::validTransferBundle(), JSON_THROW_ON_ERROR),
        )->generate($this->transferGroup());

        $this->assertCount(StudyVocabBundleGenerator::TRANSFER_SENTENCE_COUNT, $bundle['sentences']);
        $this->assertCount(StudyVocabBundleGenerator::TRANSFER_DRAFT_COUNT, $bundle['variants']);
        $this->assertCount(2, $bundle['variants']);
        $this->assertSame([1, 2], array_column($bundle['variants'], 'variantStage'));
        $this->assertSame(
            [
                'sentence_audio_recognition',
                'sentence_text_recognition',
            ],
            array_map(static fn (array $variant): string => $variant['variantKind']->value, $bundle['variants']),
        );
    }

    public function test_it_ignores_unsolicited_cloze_fields_in_transfer_responses(): void
    {
        $response = self::validTransferBundle();
        $response['sentences'][1]['clozeSuitable'] = true;
        $response['sentences'][1]['clozeText'] = '{{c1::会社}}は駅の近くです。';
        $response['sentences'][1]['clozeHint'] = 'company';

        $bundle = $this->generatorReturning(json_encode($response, JSON_THROW_ON_ERROR))
            ->generate($this->transferGroup());

        $this->assertSame('text-recognition', $bundle['variants'][1]['creationKind']->value);
        $this->assertSame('sentence_text_recognition', $bundle['variants'][1]['variantKind']->value);
        $this->assertSame([
            'cueText' => $response['sentences'][1]['sentenceJp'],
            'cueReading' => $response['sentences'][1]['sentenceReading'],
        ], $bundle['variants'][1]['prompt']);
        $this->assertNull($bundle['variants'][1]['imagePrompt']);
    }

    #[DataProvider('transferLearnerContextProvider')]
    public function test_wanikani_prompt_requires_only_known_surrounding_language(?string $summary): void
    {
        $openAi = $this->mock(OpenAiStudyCardGenerator::class);
        $openAi->shouldReceive('generateJson')->once()
            ->withArgs(function (string $instruction, string $payload) use ($summary): bool {
                $this->assertKnownContextInstructions($instruction);
                $input = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('会社', $input['targetWord']);
                $this->assertSame($summary, $input['learnerContextSummary']);

                return true;
            })
            ->andReturn(json_encode(self::validTransferBundle(), JSON_THROW_ON_ERROR));
        $context = $this->mock(StudyLearnerContextBuilder::class);
        $context->shouldReceive('build')->once()->with(1)->andReturn($summary);
        $group = $this->transferGroup();
        $group->include_learner_context = true;

        $bundle = (new StudyVocabBundleGenerator($openAi, $context))->generate($group);

        $this->assertCount(2, $bundle['variants']);
    }

    /** @return array<string, array{?string}> */
    public static function transferLearnerContextProvider(): array
    {
        return [
            'no learner history' => [null],
            'familiar basic context' => ['- recognition/review: その国は大きいです。 - That country is big.'],
            'recent exposure is not mastery' => ['- recognition/relearning (3 lapses): 共和国 - republic'],
        ];
    }

    private function assertKnownContextInstructions(string $instruction): void
    {
        foreach ([
            'targetWord is the only permitted learning target',
            'All surrounding vocabulary, word senses, readings, grammar, and conjugations must already be familiar',
            'learnerContextSummary is a limited sample of recent cards, not a vocabulary whitelist',
            'Do not infer vocabulary knowledge from known kanji, a WaniKani/JLPT level, or a word merely appearing in a recent card',
            'When learner evidence is missing or uncertain, simplify',
            'Never add an unfamiliar synonym, antonym, comparison term, compound, or topic-specific word',
            'n+1 takes priority over variety and rich context',
            'Only listening recognition and reading recognition cards are allowed',
            'Do not generate cloze or production cards',
            '社会の授業で、君主国と共和国の違いを習いました。',
            'その国は君主国です。',
            'Return exactly 2 distinct, short, natural sentences',
            'Audit every sentence before returning JSON',
        ] as $rule) {
            $this->assertStringContainsString($rule, $instruction);
        }
        $this->assertStringNotContainsString('clozeHint', $instruction);
        $this->assertStringNotContainsString('clozeText', $instruction);
        $this->assertStringNotContainsString('clozeSuitable', $instruction);
    }

    public function test_transfer_bundles_reject_duplicate_sentence_contexts(): void
    {
        $response = self::validTransferBundle();
        $response['sentences'][1]['sentenceJp'] = $response['sentences'][0]['sentenceJp'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must use distinct sentence contexts');

        $this->generatorReturning(json_encode($response, JSON_THROW_ON_ERROR))
            ->generate($this->transferGroup());
    }

    public function test_new_transfer_bundles_reject_the_old_four_sentence_response(): void
    {
        $response = self::validTransferBundle();
        $response['sentences'] = [...$response['sentences'], ...$response['sentences']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must include exactly 2 sentences');

        $this->generatorReturning(json_encode($response, JSON_THROW_ON_ERROR))
            ->generate($this->transferGroup());
    }

    public function test_it_rejects_provider_drift_from_a_supplied_source_sentence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Generated study vocab bundle changed the requested source sentence.',
        );

        $this->generatorReturning(json_encode(self::validBundle(), JSON_THROW_ON_ERROR))
            ->generate($this->group('利用者が入力した会社の文です。'));
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

    private function group(?string $sourceSentence = null): StudyVocabVariantGroup
    {
        $group = new StudyVocabVariantGroup;
        $group->user_id = 1;
        $group->target_word = '会社';
        $group->source_sentence = $sourceSentence;
        $group->source_context = null;
        $group->include_learner_context = false;

        return $group;
    }

    private function transferGroup(): StudyVocabVariantGroup
    {
        $group = $this->group();
        $group->wanikani_subject_id = 123;

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

    /** @return array<string, mixed> */
    private static function validTransferBundle(): array
    {
        $bundle = self::validBundle();
        $bundle['sentences'] = array_slice($bundle['sentences'], 0, 2);
        foreach ($bundle['sentences'] as &$sentence) {
            unset($sentence['clozeText'], $sentence['clozeHint']);
        }
        unset($sentence);

        return $bundle;
    }
}
