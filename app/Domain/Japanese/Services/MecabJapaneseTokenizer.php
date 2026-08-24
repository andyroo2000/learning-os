<?php

namespace App\Domain\Japanese\Services;

use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

final class MecabJapaneseTokenizer implements JapaneseTokenizer
{
    private bool $reportedFailure = false;

    public function tokenize(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $input = implode("\n", array_map(
            fn (string $text): string => str_replace(["\r", "\n"], ' ', $text),
            $texts,
        ))."\n";

        try {
            $process = new Process([(string) config('services.mecab.binary', 'mecab')]);
            $process->setInput($input);
            $process->setTimeout(5);
            $process->run();
        } catch (ProcessException $exception) {
            $this->reportFailure($exception->getMessage());

            return array_fill(0, count($texts), []);
        }

        if (! $process->isSuccessful()) {
            $this->reportFailure(trim($process->getErrorOutput()) ?: 'MeCab exited unsuccessfully.');

            return array_fill(0, count($texts), []);
        }

        return $this->parseOutput($process->getOutput(), count($texts));
    }

    /**
     * MeCab's IPA dictionary emits comma-separated features with the lemma at
     * index 6. Homebrew's default UniDic format is tabular with the lemma in
     * the fourth column, so accepting both keeps local development portable.
     *
     * @return list<list<array{surface: string, base: string}>>
     */
    public function parseOutput(string $output, int $expectedGroups): array
    {
        $groups = [];
        $tokens = [];

        foreach (preg_split('/\R/u', trim($output)) ?: [] as $line) {
            if ($line === 'EOS') {
                $groups[] = $tokens;
                $tokens = [];

                continue;
            }

            if ($line === '' || ! str_contains($line, "\t")) {
                continue;
            }

            $columns = explode("\t", $line);
            $surface = trim($columns[0]);
            $features = $columns[1] ?? '';

            if ($surface === '') {
                continue;
            }

            if (str_contains($features, ',')) {
                $featureColumns = explode(',', $features);
                $base = $featureColumns[6] ?? $surface;
            } else {
                $base = $columns[3] ?? $surface;
            }

            $tokens[] = [
                'surface' => $surface,
                'base' => $base === '' || $base === '*' ? $surface : $base,
            ];
        }

        if ($tokens !== []) {
            $groups[] = $tokens;
        }

        return array_pad(array_slice($groups, 0, $expectedGroups), $expectedGroups, []);
    }

    private function reportFailure(string $message): void
    {
        if ($this->reportedFailure) {
            return;
        }

        $this->reportedFailure = true;
        Log::warning('Japanese tokenization is unavailable; concept matching is using exact fields only.', [
            'error' => $message,
        ]);
    }
}
