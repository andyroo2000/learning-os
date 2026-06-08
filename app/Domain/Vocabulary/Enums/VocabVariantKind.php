<?php

namespace App\Domain\Vocabulary\Enums;

enum VocabVariantKind: string
{
    case SentenceAudioRecognition = 'sentence_audio_recognition';
    case SentenceTextRecognition = 'sentence_text_recognition';
    case WordAudioRecognition = 'word_audio_recognition';
    case WordTextRecognition = 'word_text_recognition';
    case SentenceCloze = 'sentence_cloze';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $kind): string => $kind->value,
            self::cases(),
        );
    }
}
