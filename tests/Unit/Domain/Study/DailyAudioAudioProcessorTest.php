<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Services\DailyAudioAudioProcessor;
use RuntimeException;
use Tests\TestCase;

class DailyAudioAudioProcessorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/daily-audio-processor-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_builds_array_commands_for_normalization_silence_concat_and_truncation(): void
    {
        $processor = new RecordingDailyAudioAudioProcessor;
        $input = $this->directory.'/input.mp3';
        $normalized = $this->directory.'/normalized.mp3';
        $silence = $this->directory.'/silence.mp3';
        $truncated = $this->directory.'/truncated.mp3';
        $output = $this->directory.'/output.mp3';
        file_put_contents($input, 'ID3input');

        $processor->normalize($input, $normalized);
        $processor->silence(1.25, $silence);
        $processor->truncate($input, 10, $truncated);
        $processor->concatenate(
            [$normalized, $silence, $normalized],
            $this->directory,
            $output,
        );

        $this->assertCount(4, $processor->commands);
        $this->assertSame('ffmpeg', $processor->commands[0][0]);
        $this->assertContains('dynaudnorm=f=150:g=15:p=0.9:m=10', $processor->commands[0]);
        $this->assertContains('1.25', $processor->commands[1]);
        $this->assertContains('10', $processor->commands[2]);
        $this->assertContains('concat', $processor->commands[3]);
        $this->assertStringContainsString(
            "file '{$normalized}'\nfile '{$silence}'\nfile '{$normalized}'",
            (string) file_get_contents($this->directory.'/concat.txt'),
        );
    }

    public function test_it_parses_positive_ffprobe_duration(): void
    {
        $processor = new RecordingDailyAudioAudioProcessor;
        $processor->nextOutput = "12.345\n";

        $this->assertSame(12.345, $processor->duration($this->directory.'/track.mp3'));
        $this->assertSame('ffprobe', $processor->commands[0][0]);
        $this->assertContains('format=duration', $processor->commands[0]);
    }

    public function test_it_rejects_invalid_ffprobe_output_and_missing_audio_output(): void
    {
        $processor = new RecordingDailyAudioAudioProcessor;
        $processor->nextOutput = 'not-a-duration';

        try {
            $processor->duration($this->directory.'/track.mp3');
            $this->fail('Expected invalid ffprobe output to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duration', $exception->getMessage());
        }

        $processor->createOutputs = false;
        try {
            $processor->silence(1, $this->directory.'/missing.mp3');
            $this->fail('Expected missing ffmpeg output to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('produced no output', $exception->getMessage());
        }
    }
}

class RecordingDailyAudioAudioProcessor extends DailyAudioAudioProcessor
{
    /** @var list<list<string>> */
    public array $commands = [];

    public string $nextOutput = '';

    public bool $createOutputs = true;

    protected function run(array $command): string
    {
        $this->commands[] = $command;
        $last = $command[array_key_last($command)];
        if ($this->createOutputs && $command[0] === 'ffmpeg') {
            file_put_contents($last, 'ID3processed');
        }

        return $this->nextOutput;
    }
}
