<?php

namespace App\Domain\Study\Results;

final readonly class DailyAudioLearningAtom
{
    public function __construct(
        public string $cardId,
        public string $cardType,
        public string $targetText,
        public ?string $reading,
        public string $english,
        public ?string $exampleJp,
        public ?string $exampleEn,
        public ?string $deckName,
        public ?string $noteType,
    ) {}

    /**
     * @return array{
     *     cardId: string,
     *     cardType: string,
     *     targetText: string,
     *     reading: string|null,
     *     english: string,
     *     exampleJp: string|null,
     *     exampleEn: string|null,
     *     deckName: string|null,
     *     noteType: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'cardId' => $this->cardId,
            'cardType' => $this->cardType,
            'targetText' => $this->targetText,
            'reading' => $this->reading,
            'english' => $this->english,
            'exampleJp' => $this->exampleJp,
            'exampleEn' => $this->exampleEn,
            'deckName' => $this->deckName,
            'noteType' => $this->noteType,
        ];
    }
}
