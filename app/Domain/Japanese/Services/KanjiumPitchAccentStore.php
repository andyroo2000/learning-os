<?php

namespace App\Domain\Japanese\Services;

use App\Domain\Japanese\Data\KanjiumPitchCandidate;
use RuntimeException;

class KanjiumPitchAccentStore
{
    public function __construct(
        private readonly ?string $accentsPath = null,
    ) {}

    /**
     * @return list<KanjiumPitchCandidate>
     */
    public function candidates(string $expression): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return [];
        }

        $path = $this->accentsPath ?? resource_path('data/kanjium/accents.txt.gz');
        if (! is_readable($path)) {
            throw new RuntimeException('Kanjium pitch accent data is unavailable.');
        }

        $candidates = [];
        $compressed = str_ends_with($path, '.gz');
        $file = $compressed ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Kanjium pitch accent data is unavailable.');
        }

        try {
            while (($line = $compressed ? gzgets($file) : fgets($file)) !== false) {
                if (! str_starts_with($line, $expression."\t")) {
                    continue;
                }

                foreach ($this->parseLine($line) as $candidate) {
                    $candidates[] = $candidate;
                }
            }
        } finally {
            $compressed ? gzclose($file) : fclose($file);
        }

        return $candidates;
    }

    /**
     * @return list<KanjiumPitchCandidate>
     */
    private function parseLine(string $line): array
    {
        $fields = explode("\t", trim($line));
        if (count($fields) !== 3 || $fields[0] === '' || $fields[1] === '') {
            return [];
        }

        $candidates = [];
        foreach (explode(',', $fields[2]) as $pitchNumber) {
            if (! ctype_digit($pitchNumber)) {
                continue;
            }

            $candidates[] = new KanjiumPitchCandidate(
                surface: $fields[0],
                reading: $fields[1],
                pitchNumber: (int) $pitchNumber,
            );
        }

        return $candidates;
    }
}
