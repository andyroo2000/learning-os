<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\PersistUploadedStudyAudioAction;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersistUploadedStudyAudioActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    public function test_it_persists_valid_pcm_wav_bytes_using_measured_metadata(): void
    {
        $user = User::factory()->create();
        $bytes = $this->wavBytes(sampleCount: 1_600);

        $result = resolve(PersistUploadedStudyAudioAction::class)->handle(
            $user->id,
            UploadedFile::fake()->createWithContent(' dialogue.wav ', $bytes),
        );

        $this->assertSame(strlen($bytes), $result->mediaAsset->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $result->mediaAsset->checksum_sha256);
        $this->assertSame('dialogue.wav', $result->mediaAsset->original_filename);
        $this->assertSame('audio/wav', $result->mediaAsset->mime_type);
        $this->assertSame('audio', $result->mediaRef['mediaKind']);
        Storage::disk('media')->assertExists($result->mediaAsset->path);
    }

    public function test_it_accepts_audio_at_the_exact_duration_limit(): void
    {
        $user = User::factory()->create();

        $result = resolve(PersistUploadedStudyAudioAction::class)->handle(
            $user->id,
            UploadedFile::fake()->createWithContent(
                'sixty-seconds.wav',
                $this->wavBytes(sampleCount: 16_000 * PersistUploadedStudyAudioAction::MAX_DURATION_SECONDS),
            ),
        );

        $this->assertSame('audio/wav', $result->mediaAsset->mime_type);
        Storage::disk('media')->assertExists($result->mediaAsset->path);
    }

    #[DataProvider('invalidWavProvider')]
    public function test_it_rejects_malformed_pcm_wav_bytes_without_side_effects(string $bytes): void
    {
        $user = User::factory()->create();

        try {
            resolve(PersistUploadedStudyAudioAction::class)->handle(
                $user->id,
                UploadedFile::fake()->createWithContent('dialogue.wav', $bytes),
            );
            $this->fail('Expected malformed WAV audio to be rejected.');
        } catch (StudyCardAudioValidationException $e) {
            $this->assertSame('audio', $e->field());
            $this->assertSame('The audio must be a valid PCM WAV file.', $e->getMessage());
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWavProvider(): iterable
    {
        yield 'plain text' => ['not a wav'];
        yield 'unsupported float encoding' => [self::wavBytesStatic(format: 3)];
        yield 'unsupported sample width' => [self::wavBytesStatic(bits: 8, blockAlign: 1)];
        yield 'inconsistent byte rate' => [self::wavBytesStatic(byteRate: 1)];
        yield 'partial PCM frame' => [self::wavBytesStatic(dataSuffix: "\0")];
        yield 'truncated data chunk' => [substr(self::wavBytesStatic(), 0, -1)];
        yield 'trailing partial chunk header' => [self::wavBytesStatic(trailingBytes: "\0")];
        yield 'inconsistent RIFF size' => [substr_replace(self::wavBytesStatic(), pack('V', 1), 4, 4)];
    }

    public function test_it_rejects_audio_over_the_duration_and_byte_limits_before_storage(): void
    {
        $user = User::factory()->create();
        $action = resolve(PersistUploadedStudyAudioAction::class);

        foreach ([
            [
                $this->wavBytes(sampleCount: 16_000 * 61),
                'The audio must not be longer than 60 seconds.',
            ],
            [
                str_repeat('x', PersistUploadedStudyAudioAction::MAX_UPLOAD_BYTES + 1),
                'The audio must not be larger than 10 MB.',
            ],
        ] as [$bytes, $expectedMessage]) {
            try {
                $action->handle(
                    $user->id,
                    UploadedFile::fake()->createWithContent('dialogue.wav', $bytes),
                );
                $this->fail('Expected out-of-bounds audio to be rejected.');
            } catch (StudyCardAudioValidationException $e) {
                $this->assertSame('audio', $e->field());
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    private function wavBytes(int $sampleCount = 1_600): string
    {
        return self::wavBytesStatic(sampleCount: $sampleCount);
    }

    private static function wavBytesStatic(
        int $sampleRate = 16_000,
        int $sampleCount = 1_600,
        int $format = 1,
        int $bits = 16,
        int $blockAlign = 2,
        ?int $byteRate = null,
        string $dataSuffix = '',
        string $trailingBytes = '',
    ): string {
        $data = str_repeat("\0", $sampleCount * $blockAlign).$dataSuffix;

        return 'RIFF'
            .pack('V', 36 + strlen($data) + strlen($trailingBytes))
            .'WAVEfmt '
            .pack('VvvVVvv', 16, $format, 1, $sampleRate, $byteRate ?? $sampleRate * $blockAlign, $blockAlign, $bits)
            .'data'
            .pack('V', strlen($data))
            .$data
            .$trailingBytes;
    }
}
