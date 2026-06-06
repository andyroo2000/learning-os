<?php

namespace App\Domain\Study\Enums;

use App\Domain\Flashcards\Enums\CardType;

enum StudyCardCreationKind: string
{
    case TextRecognition = 'text-recognition';
    case AudioRecognition = 'audio-recognition';
    case ProductionText = 'production-text';
    case ProductionImage = 'production-image';
    case Cloze = 'cloze';

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

    /**
     * Study drafts eventually create flashcards, so the Study domain owns the creation mode
     * while persisting the canonical Flashcards card taxonomy alongside it.
     */
    public function cardType(): CardType
    {
        return match ($this) {
            self::TextRecognition, self::AudioRecognition => CardType::Recognition,
            self::ProductionText, self::ProductionImage => CardType::Production,
            self::Cloze => CardType::Cloze,
        };
    }
}
