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

    public function test_punctuation_only_lines_and_leading_newlines_preserve_all_source_text(): void
    {
        $text = "\n！！\n映画。\n…\n好き！";
        $result = JournalSentenceTimings::build($text, [
            ['text' => '映画。', 'start' => 0, 'end' => 2],
            ['text' => '好き！', 'start' => 2, 'end' => 4],
        ]);
        $this->assertCount(2, $result);
        $this->assertSame($text, implode('', array_column($result, 'text')));
        $this->assertSame(2, $result[1]['start']);
    }

    public function test_provider_character_width_normalization_preserves_original_sentences(): void
    {
        $text = '映画の最後２０パーセントがわからなかった。 ＤＶＤでも見たい！';
        $this->assertSame([
            ['text' => '映画の最後２０パーセントがわからなかった。 ', 'start' => 12.64, 'end' => 15.84],
            ['text' => 'ＤＶＤでも見たい！', 'start' => 16.0, 'end' => 18.0],
        ], JournalSentenceTimings::build($text, [
            ['text' => '映画の最後', 'start' => 12.64, 'end' => 13.52],
            ['text' => '2', 'start' => 13.52, 'end' => 14.4],
            ['text' => '0', 'start' => 14.4, 'end' => 14.4],
            ['text' => 'パーセントがわからなかった', 'start' => 14.4, 'end' => 15.84],
            ['text' => 'DVDでも見たい', 'start' => 16.0, 'end' => 18.0],
        ]));
        $this->assertSame([], JournalSentenceTimings::build('２０。', [
            ['text' => '21', 'start' => 0, 'end' => 1],
        ]));
    }

    private function event(string $bytes, int $sequence, float $offset, array $segments): string
    {
        return 'data: '.json_encode([
            'audio_base64' => base64_encode($bytes), 'chunk_seq' => $sequence,
            'chunk_audio_offset_sec' => $offset, 'alignment' => ['segments' => $segments],
        ], JSON_THROW_ON_ERROR);
    }
}
