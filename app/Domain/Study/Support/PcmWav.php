<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyCardAudioValidationException;

final class PcmWav
{
    public static function validate(string $bytes, int $maxSeconds): void
    {
        self::validateHeader($bytes);
        $chunks = self::chunks($bytes);
        $format = self::format($chunks);
        self::validateEncoding($format);
        self::validateRates($format);
        $dataBytes = strlen($chunks['data'] ?? '');
        self::validateFrames($dataBytes, $format['blockAlign']);
        if ($dataBytes / $format['byteRate'] > $maxSeconds) {
            throw StudyCardAudioValidationException::uploadTooLong($maxSeconds);
        }
    }

    private static function validateHeader(string $bytes): void
    {
        self::require(strlen($bytes) >= 44);
        self::require(substr($bytes, 0, 4) === 'RIFF');
        self::require(substr($bytes, 8, 4) === 'WAVE');
        self::require(unpack('Vsize', substr($bytes, 4, 4))['size'] === strlen($bytes) - 8);
    }

    private static function chunks(string $bytes): array
    {
        $offset = 12;
        $chunks = [];
        while ($offset + 8 <= strlen($bytes)) {
            $type = substr($bytes, $offset, 4);
            $size = unpack('Vsize', substr($bytes, $offset + 4, 4))['size'];
            $end = $offset + 8 + $size + ($size % 2);
            self::require($end <= strlen($bytes));
            self::rememberChunk($chunks, $type, substr($bytes, $offset + 8, $size));
            $offset = $end;
        }
        self::require($offset === strlen($bytes));

        return $chunks;
    }

    private static function rememberChunk(array &$chunks, string $type, string $bytes): void
    {
        if (! in_array($type, ['fmt ', 'data'], true)) {
            return;
        }
        self::require(! array_key_exists($type, $chunks));
        $chunks[$type] = $bytes;
    }

    private static function format(array $chunks): array
    {
        $bytes = $chunks['fmt '] ?? '';
        self::require(strlen($bytes) >= 16);

        return unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', substr($bytes, 0, 16));
    }

    private static function validateEncoding(array $format): void
    {
        self::require($format['format'] === 1);
        self::require(in_array($format['channels'], [1, 2], true));
        self::require($format['bits'] === 16);
        self::require($format['blockAlign'] === $format['channels'] * 2);
    }

    private static function validateRates(array $format): void
    {
        self::require($format['sampleRate'] >= 8000);
        self::require($format['sampleRate'] <= 96000);
        self::require($format['byteRate'] === $format['sampleRate'] * $format['blockAlign']);
    }

    private static function validateFrames(int $bytes, int $blockAlign): void
    {
        self::require($bytes >= $blockAlign);
        self::require($bytes % $blockAlign === 0);
    }

    private static function require(bool $valid): void
    {
        if (! $valid) {
            throw StudyCardAudioValidationException::invalidUpload();
        }
    }
}
