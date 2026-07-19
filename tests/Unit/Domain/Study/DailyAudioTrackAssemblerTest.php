<?php

namespace Tests\Unit\Domain\Study;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Services\DailyAudioAudioProcessor;
use App\Domain\Study\Services\DailyAudioTrackAssembler;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DailyAudioTrackAssemblerTest extends TestCase
{
    private string $practiceId;

    private string $trackId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('daily-audio-test');
        config()->set('daily_audio.disk', 'daily-audio-test');
        $this->practiceId = (string) str()->uuid();
        $this->trackId = (string) str()->uuid();
    }

    public function test_it_assembles_typed_units_reuses_speech_and_silence_and_persists_the_track(): void
    {
        $speech = $this->mock(FishAudioSpeechGenerator::class);
        $speech->shouldReceive('generate')
            ->once()
            ->with('Welcome.', 'fishaudio:narrator', 1.0)
            ->andReturn('ID3narration');
        $speech->shouldReceive('generate')
            ->once()
            ->with('物価が高いです。', 'fishaudio:speaker', 0.85)
            ->andReturn('ID3japanese');
        $audio = $this->audioProcessor();

        $result = (new DailyAudioTrackAssembler($speech, $audio))->assemble(
            '  '.strtoupper($this->practiceId).'  ',
            strtoupper($this->trackId),
            [
                DailyAudioScriptUnit::marker('intro'),
                DailyAudioScriptUnit::narration('Welcome.', 'fishaudio:narrator'),
                DailyAudioScriptUnit::narration('Welcome.', 'fishaudio:narrator'),
                DailyAudioScriptUnit::pause(2),
                DailyAudioScriptUnit::pause(2),
                DailyAudioScriptUnit::targetLanguage(
                    '物価が高いです。',
                    '物価[ぶっか]が高[たか]いです。',
                    'The cost of living is high.',
                    'fishaudio:speaker',
                    0.85,
                ),
            ],
        );

        $expectedPath = "daily-audio/{$this->practiceId}/{$this->trackId}.mp3";
        $this->assertSame($expectedPath, $result->storagePath);
        $this->assertSame(12, $result->durationSeconds);
        $this->assertSame([
            ['unitIndex' => 1, 'startTime' => 0, 'endTime' => 1250],
            ['unitIndex' => 2, 'startTime' => 1250, 'endTime' => 2500],
            ['unitIndex' => 3, 'startTime' => 2500, 'endTime' => 4500],
            ['unitIndex' => 4, 'startTime' => 4500, 'endTime' => 6500],
            ['unitIndex' => 5, 'startTime' => 6500, 'endTime' => 8000],
        ], $result->timingData);
        $this->assertSame([
            'unitCount' => 6,
            'spokenUnitCount' => 3,
            'pauseUnitCount' => 2,
            'uniqueSynthesisCount' => 2,
            'reusedSynthesisCount' => 1,
        ], $result->metadata);
        Storage::disk('daily-audio-test')->assertExists($expectedPath);
        $this->assertSame(
            'ID3assembled-track',
            Storage::disk('daily-audio-test')->get($expectedPath),
        );
    }

    public function test_it_retries_then_truncates_degenerate_provider_audio(): void
    {
        $speech = $this->mock(FishAudioSpeechGenerator::class);
        $speech->shouldReceive('generate')
            ->twice()
            ->with('短い', 'fishaudio:speaker', 1.0)
            ->andReturn('ID3degenerate');
        $audio = $this->mock(DailyAudioAudioProcessor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('normalize')
                ->twice()
                ->andReturnUsing(function (string $input, string $output): void {
                    copy($input, $output);
                });
            $mock->shouldReceive('duration')
                ->withArgs(fn (string $path): bool => str_contains($path, '-1.mp3'))
                ->once()
                ->andReturn(40.0);
            $mock->shouldReceive('duration')
                ->withArgs(fn (string $path): bool => str_contains($path, '-2.mp3'))
                ->once()
                ->andReturn(35.0);
            $mock->shouldReceive('truncate')
                ->once()
                ->withArgs(function (string $input, float $seconds, string $output): bool {
                    $this->assertStringContainsString('-2.mp3', $input);
                    $this->assertSame(10.0, $seconds);
                    file_put_contents($output, 'ID3truncated');

                    return true;
                });
            $mock->shouldReceive('duration')
                ->withArgs(fn (string $path): bool => str_ends_with($path, '-truncated.mp3'))
                ->once()
                ->andReturn(10.0);
            $mock->shouldReceive('concatenate')
                ->once()
                ->andReturnUsing(function (array $segments, string $directory, string $output): void {
                    $this->assertCount(1, $segments);
                    file_put_contents($output, 'ID3assembled-track');
                });
            $mock->shouldReceive('duration')
                ->withArgs(fn (string $path): bool => str_ends_with($path, '/track.mp3'))
                ->once()
                ->andReturn(10.0);
        });

        $result = (new DailyAudioTrackAssembler($speech, $audio))->assemble(
            $this->practiceId,
            $this->trackId,
            [DailyAudioScriptUnit::targetLanguage(
                '短い',
                null,
                'Short.',
                'fishaudio:speaker',
                1,
            )],
        );

        $this->assertSame(10, $result->durationSeconds);
        $this->assertSame([
            ['unitIndex' => 0, 'startTime' => 0, 'endTime' => 10000],
        ], $result->timingData);
    }

    public function test_it_does_not_persist_a_partial_track_when_concatenation_fails(): void
    {
        $speech = $this->mock(FishAudioSpeechGenerator::class);
        $speech->shouldReceive('generate')->once()->andReturn('ID3speech');
        $audio = $this->mock(DailyAudioAudioProcessor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('normalize')
                ->once()
                ->andReturnUsing(fn (string $input, string $output): bool => copy($input, $output));
            $mock->shouldReceive('duration')->once()->andReturn(1.0);
            $mock->shouldReceive('concatenate')
                ->once()
                ->andThrow(new RuntimeException('ffmpeg failed'));
        });

        try {
            (new DailyAudioTrackAssembler($speech, $audio))->assemble(
                $this->practiceId,
                $this->trackId,
                [DailyAudioScriptUnit::narration('Welcome.', 'fishaudio:narrator')],
            );
            $this->fail('Expected audio concatenation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ffmpeg failed', $exception->getMessage());
        }

        Storage::disk('daily-audio-test')->assertDirectoryEmpty('daily-audio');
    }

    public function test_it_rejects_invalid_ids_and_untyped_or_oversized_scripts(): void
    {
        $assembler = new DailyAudioTrackAssembler(
            $this->mock(FishAudioSpeechGenerator::class),
            $this->mock(DailyAudioAudioProcessor::class),
        );

        foreach ([
            ['bad-id', $this->trackId, [DailyAudioScriptUnit::pause(1)]],
            [$this->practiceId, $this->trackId, []],
            [$this->practiceId, $this->trackId, ['not-a-unit']],
            [
                $this->practiceId,
                $this->trackId,
                array_fill(
                    0,
                    DailyAudioTrackAssembler::MAX_SCRIPT_UNITS + 1,
                    DailyAudioScriptUnit::pause(1),
                ),
            ],
        ] as [$practiceId, $trackId, $units]) {
            try {
                $assembler->assemble($practiceId, $trackId, $units);
                $this->fail('Expected invalid assembly input to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_script_units_reject_non_finite_or_out_of_range_audio_controls(): void
    {
        foreach ([
            fn (): DailyAudioScriptUnit => DailyAudioScriptUnit::pause(INF),
            fn (): DailyAudioScriptUnit => DailyAudioScriptUnit::pause(
                DailyAudioScriptUnit::MAX_PAUSE_SECONDS + 0.1,
            ),
            fn (): DailyAudioScriptUnit => DailyAudioScriptUnit::targetLanguage(
                '日本語',
                null,
                'Japanese',
                'fishaudio:speaker',
                NAN,
            ),
            fn (): DailyAudioScriptUnit => DailyAudioScriptUnit::targetLanguage(
                '日本語',
                null,
                'Japanese',
                'fishaudio:speaker',
                DailyAudioScriptUnit::MAX_SPEECH_SPEED + 0.1,
            ),
        ] as $unit) {
            try {
                $unit();
                $this->fail('Expected an unsafe audio control to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function audioProcessor(): DailyAudioAudioProcessor&MockInterface
    {
        return $this->mock(DailyAudioAudioProcessor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('normalize')
                ->twice()
                ->andReturnUsing(function (string $input, string $output): void {
                    copy($input, $output);
                });
            $mock->shouldReceive('silence')
                ->once()
                ->withArgs(function (float $seconds, string $output): bool {
                    $this->assertSame(2.0, $seconds);
                    file_put_contents($output, 'ID3silence');

                    return true;
                });
            $mock->shouldReceive('duration')
                ->andReturnUsing(function (string $path): float {
                    return match (true) {
                        str_ends_with($path, '/track.mp3') => 12.4,
                        str_contains($path, 'silence-') => 2.0,
                        str_contains($path, 'speech-0-') => 1.25,
                        default => 1.5,
                    };
                });
            $mock->shouldReceive('concatenate')
                ->once()
                ->withArgs(function (array $segments, string $directory, string $output): bool {
                    $this->assertCount(5, $segments);
                    $this->assertSame($directory.'/track.mp3', $output);
                    $this->assertSame($segments[0], $segments[1]);
                    $this->assertSame($segments[2], $segments[3]);
                    file_put_contents($output, 'ID3assembled-track');

                    return true;
                });
        });
    }
}
