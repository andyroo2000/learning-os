<?php

namespace Tests\Unit\Domain\Study\Support;

use App\Domain\Study\Support\StudyCardPayloadSchema;
use PHPUnit\Framework\TestCase;

class StudyCardPayloadSchemaTest extends TestCase
{
    public function test_it_publishes_the_owned_prompt_and_answer_field_types(): void
    {
        $schema = StudyCardPayloadSchema::jsonSchema();

        $this->assertSame(1, $schema['version']);
        $this->assertSame(['prompt', 'answer'], $schema['required']);
        $this->assertSame(
            ['$ref' => '#/$defs/nullableString'],
            $schema['properties']['prompt']['properties']['cueText'],
        );
        $this->assertSame(['type' => ['string', 'null']], $schema['$defs']['nullableString']);
        $this->assertSame(
            ['$ref' => '#/$defs/media'],
            $schema['properties']['prompt']['properties']['cueAudio'],
        );
        $this->assertSame(
            ['$ref' => '#/$defs/pitchAccent'],
            $schema['properties']['answer']['properties']['pitchAccent'],
        );
        $this->assertTrue($schema['properties']['prompt']['additionalProperties']);
        $this->assertTrue($schema['properties']['answer']['additionalProperties']);
    }

    public function test_it_rejects_wrong_types_for_api_owned_fields_but_preserves_extensions(): void
    {
        $errors = StudyCardPayloadSchema::validationErrors(
            [
                'cueText' => ['not text'],
                'cueAudio' => ['filename' => 123],
                'futurePromptField' => ['preserved' => true],
            ],
            [
                'meaning' => false,
                'pitchAccent' => 'invalid',
                'futureAnswerField' => ['preserved' => true],
            ],
        );

        $this->assertSame([
            'prompt.cueText' => 'prompt.cueText must be a string or null.',
            'prompt.cueAudio.filename' => 'prompt.cueAudio.filename must be a string or null.',
            'answer.meaning' => 'answer.meaning must be a string or null.',
            'answer.pitchAccent' => 'answer.pitchAccent must be an object or null.',
        ], $errors);
    }

    public function test_it_accepts_nullable_owned_fields_and_empty_json_objects(): void
    {
        $this->assertSame([], StudyCardPayloadSchema::validationErrors(
            ['cueText' => null, 'cueAudio' => []],
            ['meaning' => 'company', 'answerImage' => null, 'pitchAccent' => []],
        ));
    }

    public function test_it_rejects_non_empty_lists_where_json_objects_are_required(): void
    {
        $this->assertSame(
            ['prompt' => 'prompt must be an object.'],
            StudyCardPayloadSchema::validationErrors(
                [['cueText' => 'nested list item']],
                ['meaning' => 'company'],
            ),
        );
    }
}
