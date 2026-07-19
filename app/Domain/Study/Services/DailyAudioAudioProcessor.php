<?php

namespace App\Domain\Study\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class DailyAudioAudioProcessor
{
    public const PROCESS_TIMEOUT_SECONDS = 180;

    public function normalize(string $inputPath, string $outputPath): void
    {
        $this->run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-af',
            'dynaudnorm=f=150:g=15:p=0.9:m=10',
            '-ar',
            '44100',
            '-ac',
            '2',
            '-c:a',
            'libmp3lame',
            '-b:a',
            '128k',
            $outputPath,
        ]);
        $this->assertOutput($outputPath);
    }

    public function silence(float $seconds, string $outputPath): void
    {
        $this->run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=44100:cl=stereo',
            '-t',
            $this->decimal($seconds),
            '-c:a',
            'libmp3lame',
            '-b:a',
            '128k',
            $outputPath,
        ]);
        $this->assertOutput($outputPath);
    }

    /**
     * @param  list<string>  $segmentPaths
     */
    public function concatenate(array $segmentPaths, string $workingDirectory, string $outputPath): void
    {
        if ($segmentPaths === []) {
            throw new RuntimeException('Daily Audio track has no audio segments.');
        }

        $listPath = $workingDirectory.'/concat.txt';
        $lines = array_map(
            fn (string $path): string => "file '".$this->concatPath($path)."'",
            $segmentPaths,
        );
        if (file_put_contents($listPath, implode("\n", $lines)."\n", LOCK_EX) === false) {
            throw new RuntimeException('Daily Audio concat manifest could not be written.');
        }

        $this->run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $listPath,
            '-af',
            implode(',', [
                'highpass=f=80',
                'acompressor=threshold=-20dB:ratio=2:attack=20:release=250:makeup=2dB',
                'equalizer=f=3000:t=q:w=1:g=2',
                'dynaudnorm=f=150:g=15:p=0.9:m=10',
            ]),
            '-ar',
            '44100',
            '-ac',
            '2',
            '-c:a',
            'libmp3lame',
            '-b:a',
            '128k',
            $outputPath,
        ]);
        $this->assertOutput($outputPath);
    }

    public function truncate(string $inputPath, float $seconds, string $outputPath): void
    {
        $this->run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-t',
            $this->decimal($seconds),
            '-ar',
            '44100',
            '-ac',
            '2',
            '-c:a',
            'libmp3lame',
            '-b:a',
            '128k',
            $outputPath,
        ]);
        $this->assertOutput($outputPath);
    }

    public function duration(string $path): float
    {
        $output = $this->run([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $duration = filter_var(trim($output), FILTER_VALIDATE_FLOAT);

        if (! is_float($duration) || ! is_finite($duration) || $duration <= 0) {
            throw new RuntimeException('Daily Audio segment duration could not be determined.');
        }

        return $duration;
    }

    /**
     * @param  list<string>  $command
     */
    protected function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->mustRun();

        return $process->getOutput();
    }

    private function assertOutput(string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Daily Audio audio processing produced no output.');
        }
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function concatPath(string $path): string
    {
        return str_replace("'", "'\\''", $path);
    }
}
