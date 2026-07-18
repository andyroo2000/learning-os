<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Data\CreateStudyVocabBundleData;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreateStudyVocabBundleDataTest extends TestCase
{
    public function test_it_normalizes_valid_direct_caller_input_and_accepts_maximum_lengths(): void
    {
        $data = CreateStudyVocabBundleData::fromInput(
            userId: 1,
            targetWord: ' '.str_repeat('語', CreateStudyVocabBundleData::MAX_TARGET_WORD_LENGTH).' ',
            sourceSentence: ' '.str_repeat('文', CreateStudyVocabBundleData::MAX_SOURCE_SENTENCE_LENGTH).' ',
            context: ' '.str_repeat('背', CreateStudyVocabBundleData::MAX_CONTEXT_LENGTH).' ',
            includeLearnerContext: false,
        );

        $this->assertSame(CreateStudyVocabBundleData::MAX_TARGET_WORD_LENGTH, mb_strlen($data->targetWord));
        $this->assertSame(CreateStudyVocabBundleData::MAX_SOURCE_SENTENCE_LENGTH, mb_strlen($data->sourceSentence));
        $this->assertSame(CreateStudyVocabBundleData::MAX_CONTEXT_LENGTH, mb_strlen($data->context));
        $this->assertFalse($data->includeLearnerContext);
    }

    public function test_blank_optional_values_normalize_to_null(): void
    {
        $data = CreateStudyVocabBundleData::fromInput(1, ' 会社 ', '   ', '', true);

        $this->assertSame('会社', $data->targetWord);
        $this->assertNull($data->sourceSentence);
        $this->assertNull($data->context);
    }

    #[DataProvider('invalidStringProvider')]
    public function test_it_rejects_invalid_direct_caller_strings(
        string $targetWord,
        ?string $sourceSentence,
        ?string $context,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        CreateStudyVocabBundleData::fromInput(
            1,
            $targetWord,
            $sourceSentence,
            $context,
            true,
        );
    }

    /** @return array<string, array{string, ?string, ?string}> */
    public static function invalidStringProvider(): array
    {
        return [
            'blank target' => ['', null, null],
            'long target' => [
                str_repeat('a', CreateStudyVocabBundleData::MAX_TARGET_WORD_LENGTH + 1),
                null,
                null,
            ],
            'long source sentence' => [
                '会社',
                str_repeat('a', CreateStudyVocabBundleData::MAX_SOURCE_SENTENCE_LENGTH + 1),
                null,
            ],
            'long context' => [
                '会社',
                null,
                str_repeat('a', CreateStudyVocabBundleData::MAX_CONTEXT_LENGTH + 1),
            ],
        ];
    }

    public function test_it_rejects_nonpositive_user_ids(): void
    {
        $this->expectException(LogicException::class);

        CreateStudyVocabBundleData::fromInput(0, '会社', null, null, true);
    }
}
