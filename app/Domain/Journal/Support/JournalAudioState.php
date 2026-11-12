<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class JournalAudioState
{
    public static function path(JournalEntry $entry, string $voice, string $text): string
    {
        return 'journal/'.$entry->id.'/audio/'.hash('sha256', $voice.'|'.$text).'.mp3';
    }

    public static function selection(JournalEntry $entry, string $text): array
    {
        $voices = config('journal_voices');
        // Recover pre-preference audio (and restored older revisions) without synthesis or writes.
        $cached = self::savedVoice($entry, $text, $voices) ?? self::cachedVoice($entry, $text, array_keys($voices));
        if ($cached !== null) {
            return ['voiceId' => $cached, 'hasAudio' => true];
        }
        $preferred = DB::table('journal_audio_preferences')->where('user_id', $entry->user_id)->value('voice_id');

        return ['voiceId' => isset($voices[$preferred]) ? $preferred : array_key_first($voices), 'hasAudio' => false];
    }

    private static function savedVoice(JournalEntry $entry, string $text, array $voices): ?string
    {
        $voice = $entry->audio_voice_id;
        if (! isset($voices[$voice]) || $entry->audio_text_hash !== hash('sha256', $text)) {
            return null;
        }

        return Storage::disk('local')->exists(self::path($entry, $voice, $text)) ? $voice : null;
    }

    private static function cachedVoice(JournalEntry $entry, string $text, array $voices): ?string
    {
        $files = [];
        $disk = Storage::disk('local');
        foreach ($voices as $voice) {
            $path = self::path($entry, $voice, $text);
            if ($disk->exists($path)) {
                $files[$voice] = $disk->lastModified($path);
            }
        }
        arsort($files);

        return array_key_first($files);
    }

    /** Call only when publishing newly synthesized audio inside the owner's write transaction. */
    public static function rememberGeneration(JournalEntry $entry, string $voice, string $text): void
    {
        DB::table('journal_entries')->where('id', $entry->id)->update([
            'audio_voice_id' => $voice, 'audio_text_hash' => hash('sha256', $text),
        ]);
        DB::table('journal_audio_preferences')->updateOrInsert(['user_id' => $entry->user_id], ['voice_id' => $voice]);
    }
}
