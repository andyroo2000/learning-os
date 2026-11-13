<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Support\JournalSentenceTimings;
use App\Support\Audio\FishAudioTimestampStream;
use PHPUnit\Framework\TestCase;

final class JournalSentenceTimingsTest extends TestCase
{
    public function test_cumulative_snapshots_replace_old_timings_and_apply_chunk_offsets(): void
    {
        $events = [
            $this->event('ID3', 0, 0, [['text' => '昨日。', 'start' => 0, 'end' => 1]]),
            $this->event('audio', 0, 0, [['text' => '昨日。', 'start' => 0.2, 'end' => 2]]),
            $this->event('more', 1, 2.5, [['text' => '今日！', 'start' => 0.1, 'end' => 1.5]]),
        ];
        $result = FishAudioTimestampStream::decode(implode("\n\n", $events));
        $this->assertSame('ID3audiomore', $result['audio']);
        $this->assertSame([
            ['text' => '昨日。', 'start' => 0.2, 'end' => 2],
            ['text' => '今日！', 'start' => 2.6, 'end' => 4.0],
        ], $result['segments']);
    }

    public function test_sentences_preserve_source_text_and_combine_word_timings(): void
    {
        $segments = [
            ['text' => '昨日', 'start' => 0.2, 'end' => 1.0],
            ['text' => '映画を見た。', 'start' => 1.0, 'end' => 2.5],
            ['text' => '「楽しかった！」', 'start' => 3.0, 'end' => 4.0],
        ];
        $this->assertSame([
            ['text' => "昨日 映画を見た。\n", 'start' => 0.2, 'end' => 2.5],
            ['text' => '「楽しかった！」', 'start' => 3.0, 'end' => 4.0],
        ], JournalSentenceTimings::build("昨日 映画を見た。\n「楽しかった！」", $segments));
    }

    public function test_missing_or_mismatched_alignment_never_invents_timings(): void
    {
        $this->assertSame([], JournalSentenceTimings::build('昨日。今日。', []));
        $this->assertSame([], JournalSentenceTimings::build('昨日。今日。', [['text' => '別の文章。', 'start' => 0, 'end' => 2]]));
        $this->assertSame([], JournalSentenceTimings::build('昨日。今日。', [['text' => '昨日。今日。', 'start' => 0, 'end' => 2]]));
    }

    private function event(string $bytes, int $sequence, float $offset, array $segments): string
    {
        return 'data: '.json_encode([
            'audio_base64' => base64_encode($bytes), 'chunk_seq' => $sequence,
            'chunk_audio_offset_sec' => $offset, 'alignment' => ['segments' => $segments],
        ], JSON_THROW_ON_ERROR);
    }
}
