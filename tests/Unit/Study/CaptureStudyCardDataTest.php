<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Data\CaptureStudyCardData;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use PHPUnit\Framework\TestCase;

class CaptureStudyCardDataTest extends TestCase
{
    public function test_it_normalizes_direct_caller_text_and_ids_at_the_limits(): void
    {
        $data = new CaptureStudyCardData(' 01K4VY1PRBJM6H3ZXKMVYDQQ1T ', [
            'japanese' => str_repeat('あ', 2000),
            'english' => ' '.str_repeat('a', 4000).' ',
            'notes' => str_repeat('n', 3000),
        ]);
        $this->assertSame('01k4vy1prbjm6h3zxkmvydqq1t', $data->id);
        $this->assertSame(2000, mb_strlen($data->answer['expression']));
        $this->assertSame(4000, strlen($data->answer['meaning']));
        $this->assertSame(3000, strlen($data->answer['notes']));
    }

    public function test_it_rejects_invalid_direct_input(): void
    {
        foreach ([
            ['id' => 'bad', 'japanese' => '一', 'english' => 'one', 'field' => 'id'],
            ['japanese' => '', 'english' => 'one', 'field' => 'japanese'],
            ['japanese' => ['一'], 'english' => 'one', 'field' => 'japanese'],
            ['japanese' => str_repeat('一', 2001), 'english' => 'one', 'field' => 'japanese'],
            ['japanese' => '一', 'english' => str_repeat('a', 4001), 'field' => 'english'],
        ] as $input) {
            try {
                new CaptureStudyCardData($input['id'] ?? '01K4VY1PRBJM6H3ZXKMVYDQQ1T', $input);
                $this->fail('Invalid capture input was accepted.');
            } catch (StudyCardAudioValidationException $error) {
                $this->assertSame($input['field'], $error->field());
            }
        }
    }
}
