<?php

namespace Tests\Unit\Support;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Support\Audio\AudioProcessor;
use App\Support\Audio\AudioScriptUnit;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\AudioTrackAssembler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AudioTrackAssemblerInputTest extends TestCase
{
    #[DataProvider('invalidUnitProvider')]
    public function test_it_rejects_unsafe_shared_audio_controls_before_provider_or_storage_work(
        string $type,
        ?float $speed,
        ?float $pause,
    ): void {
        $speech = $this->mock(AudioSpeechGenerator::class);
        $speech->shouldNotReceive('generate');
        $audio = $this->mock(AudioProcessor::class);
        $audio->shouldNotReceive('silence', 'normalize', 'concatenate');

        $unit = new class($type, $speed, $pause) implements AudioScriptUnit
        {
            public function __construct(
                private readonly string $type,
                private readonly ?float $speed,
                private readonly ?float $pause,
            ) {}

            public function audioType(): string
            {
                return $this->type;
            }

            public function audioText(): ?string
            {
                return 'text';
            }

            public function audioVoiceId(): ?string
            {
                return 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';
            }

            public function audioSpeed(): ?float
            {
                return $this->speed;
            }

            public function audioPauseSeconds(): ?float
            {
                return $this->pause;
            }
        };

        $this->expectException(InvalidArgumentException::class);
        (new AudioTrackAssembler($speech, $audio))->assemble(
            [$unit],
            'media',
            'audio/track.mp3',
            'audio-test',
            'Test audio',
        );
    }

    /** @return array<string, array{string, float|null, float|null}> */
    public static function invalidUnitProvider(): array
    {
        return [
            'unknown type' => ['unknown', null, null],
            'non-finite speed' => ['L2', INF, null],
            'speed below provider range' => ['L2', 0.49, null],
            'pause above provider range' => ['pause', null, 60.1],
        ];
    }

    public function test_it_rejects_storage_path_traversal_before_processing(): void
    {
        $speech = $this->mock(AudioSpeechGenerator::class);
        $speech->shouldNotReceive('generate');
        $audio = $this->mock(AudioProcessor::class);
        $audio->shouldNotReceive('silence');

        $this->expectException(InvalidArgumentException::class);
        (new AudioTrackAssembler($speech, $audio))->assemble(
            [DailyAudioScriptUnit::pause(1)],
            'media',
            '../private.mp3',
            'audio-test',
            'Test audio',
        );
    }
}
