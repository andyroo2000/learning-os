<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Support\DailyAudioGenerationProgress;
use App\Support\Audio\AudioScriptUnit;
use App\Support\Audio\AudioSpeechGenerator;

final class DailyAudioSpeechBatch implements AudioSpeechGenerator
{
    /** @var array<string, array{string, string, float}> */
    private array $pending = [];

    /** @var array<string, string> */
    private array $audio = [];

    private int $total;

    private bool $parallel = true;

    /** @param iterable<mixed> $units */
    public function __construct(
        private readonly FishAudioSpeechGenerator $speech,
        iterable $units,
        private readonly ?DailyAudioGenerationProgress $progress,
    ) {
        foreach ($units as $unit) {
            if (! $unit instanceof AudioScriptUnit) {
                continue;
            }
            if ($unit->audioText() === null || $unit->audioVoiceId() === null) {
                continue;
            }
            $line = [$unit->audioText(), $unit->audioVoiceId(), $unit->audioSpeed() ?? 1.0];
            $this->pending[$this->key(...$line)] = $line;
        }
        $this->total = count($this->pending);
    }

    public function generate(string $text, string $voiceId, float $speed = 1.0): string
    {
        $key = $this->key($text, $voiceId, $speed);
        if (isset($this->pending[$key])) {
            $this->fill($key);
        }
        if (! isset($this->audio[$key])) {
            // A rejected, abnormally long clip needs fresh audio on its second attempt.
            return $this->speech->generate($text, $voiceId, $speed);
        }
        $bytes = $this->audio[$key];
        unset($this->audio[$key]);

        return $bytes;
    }

    private function fill(string $key): void
    {
        $batch = [$key => $this->pending[$key]];
        unset($this->pending[$key]);
        $next = $this->parallel && $this->audio === [] ? array_key_first($this->pending) : null;
        if ($next !== null) {
            $batch[$next] = $this->pending[$next];
            unset($this->pending[$next]);
            $this->audio += $this->speech->generateBatch($batch, function (): void {
                // A constrained provider should see only single requests for the rest of this drill.
                $this->parallel = false;
            });
        } else {
            $this->audio[$key] = $this->speech->generate(...$batch[$key]);
        }
        $this->progress?->report(10 + (int) floor(85 * ($this->total - count($this->pending)) / $this->total));
    }

    private function key(string $text, string $voiceId, float $speed): string
    {
        return hash('sha256', implode("\0", [$voiceId, $text, number_format($speed, 3, '.', '')]));
    }
}
