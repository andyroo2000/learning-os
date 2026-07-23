<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Contracts\ToolAnalyticsLogger;
use RuntimeException;

final class JsonLineToolAnalyticsLogger implements ToolAnalyticsLogger
{
    public function __construct(private readonly string $streamUri = 'php://stdout') {}

    public function write(array $event): void
    {
        // This intentionally bypasses Monolog: downstream analytics expects one
        // unprefixed JSON object per line, while the container captures stdout.
        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $stream = fopen($this->streamUri, 'ab');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the analytics output stream.');
        }

        try {
            if (fwrite($stream, $line) !== strlen($line)) {
                throw new RuntimeException('Unable to write the complete analytics event.');
            }
        } finally {
            fclose($stream);
        }
    }
}
