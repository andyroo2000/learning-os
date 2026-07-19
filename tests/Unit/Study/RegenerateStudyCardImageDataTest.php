<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Data\RegenerateStudyCardImageData;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RegenerateStudyCardImageDataTest extends TestCase
{
    public function test_it_normalizes_valid_direct_caller_input(): void
    {
        $data = RegenerateStudyCardImageData::fromInput(
            imagePrompt: '  A Tokyo office.  ',
            imagePlacement: ' BOTH ',
        );

        $this->assertSame('A Tokyo office.', $data->imagePrompt);
        $this->assertSame(StudyCardImagePlacement::Both, $data->imagePlacement);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty prompt' => ['', 'prompt', 'imagePrompt'];
        yield 'whitespace prompt' => ['   ', 'answer', 'imagePrompt'];
        yield 'oversized prompt' => [
            str_repeat('a', StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH + 1),
            'both',
            'imagePrompt',
        ];
        yield 'none role' => ['A scene.', 'none', 'imageRole'];
        yield 'blank role' => ['A scene.', '   ', 'imageRole'];
        yield 'unknown role' => ['A scene.', 'sideways', 'imageRole'];
    }

    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_direct_caller_input(
        string $prompt,
        string $role,
        string $expectedField,
    ): void {
        try {
            RegenerateStudyCardImageData::fromInput($prompt, $role);
            $this->fail("Expected invalid {$expectedField} input to be rejected.");
        } catch (StudyCardImageValidationException $exception) {
            $this->assertSame($expectedField, $exception->field());
        }
    }
}
