<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final class StudyImportCollectionDatabaseReader
{
    public function __construct(
        private readonly StudyImportCollectionCardReader $cardReader,
    ) {}

    public function read(string $collectionPath): StudyImportArchiveRead
    {
        try {
            $pdo = new PDO('sqlite:'.$collectionPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->readDatabase($pdo);
        } catch (StudyImportPreviewException $exception) {
            throw $exception;
        } catch (PDOException|JsonException|RuntimeException $exception) {
            throw StudyImportPreviewException::invalidCollectionDatabase($exception);
        }
    }

    private function readDatabase(PDO $pdo): StudyImportArchiveRead
    {
        $deck = $this->targetDeck($pdo);

        if (mb_strlen($deck->name) > Deck::MAX_NAME_LENGTH) {
            throw StudyImportPreviewException::deckNameTooLong(Deck::MAX_NAME_LENGTH);
        }

        $cards = $this->cardReader->read($pdo, $deck->sourceDeckId);

        if ($cards === []) {
            throw new StudyImportPreviewException('Deck "'.$deck->name.'" has no cards to import.');
        }

        return new StudyImportArchiveRead(
            deckName: $deck->name,
            cards: $cards,
            reviewLogs: $this->fetchTargetDeckReviewLogs($pdo, $deck->sourceDeckId),
            mediaManifestByFilename: [],
        );
    }

    private function targetDeck(PDO $pdo): StudyImportArchiveDeck
    {
        $cardDeckIds = $this->deckIdsWithCards($pdo);

        if ($this->hasTable($pdo, 'decks')) {
            return $this->selectSupportedDeck($this->fetchNormalizedDecks($pdo), $cardDeckIds);
        }

        $collectionRow = $this->collectionMetadata($pdo);

        return $this->selectSupportedDeck($this->decodeLegacyDecks((string) ($collectionRow['decks'] ?? '{}')), $cardDeckIds);
    }

    /**
     * @return array<int, true>
     */
    private function deckIdsWithCards(PDO $pdo): array
    {
        if (! $this->hasTable($pdo, 'cards')) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        $deckIds = [];

        foreach ($this->fetchAll($pdo, 'SELECT did FROM cards GROUP BY did') as $row) {
            if (isset($row['did']) && is_numeric($row['did'])) {
                $deckIds[(int) $row['did']] = true;
            }
        }

        return $deckIds;
    }

    /**
     * @return list<StudyImportArchiveDeck>
     */
    private function fetchNormalizedDecks(PDO $pdo): array
    {
        $decks = [];

        foreach ($this->fetchAll($pdo, 'SELECT id, name FROM decks ORDER BY id ASC') as $row) {
            $deck = $this->normalizedDeck($row);

            if ($deck !== null) {
                $decks[] = $deck;
            }
        }

        return $decks;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizedDeck(array $row): ?StudyImportArchiveDeck
    {
        if (! isset($row['id']) || ! is_numeric($row['id'])) {
            return null;
        }

        if (! isset($row['name']) || ! is_string($row['name'])) {
            return null;
        }

        return new StudyImportArchiveDeck(
            sourceDeckId: (int) $row['id'],
            name: trim(str_replace("\0", '', $row['name'])),
        );
    }

    /**
     * @return list<StudyImportArchiveDeck>
     */
    private function decodeLegacyDecks(string $decksJson): array
    {
        $decks = [];

        foreach ($this->decodeJsonObject($decksJson) as $deck) {
            if (! is_array($deck)) {
                continue;
            }

            if (! isset($deck['id']) || ! is_numeric($deck['id'])) {
                continue;
            }

            if (! isset($deck['name']) || ! is_string($deck['name'])) {
                continue;
            }

            $decks[] = new StudyImportArchiveDeck(
                sourceDeckId: (int) $deck['id'],
                name: trim(str_replace("\0", '', $deck['name'])),
            );
        }

        return $decks;
    }

    /**
     * @param  list<StudyImportArchiveDeck>  $decks
     * @param  array<int, true>  $cardDeckIds
     */
    private function selectSupportedDeck(array $decks, array $cardDeckIds): StudyImportArchiveDeck
    {
        $candidateDecks = $this->candidateDecks($decks, $cardDeckIds);

        $this->assertValidDeckNames($candidateDecks);

        if ($cardDeckIds !== [] && $candidateDecks === []) {
            throw new StudyImportPreviewException('The uploaded collection references cards from decks that are missing from deck metadata.');
        }

        $defaultDeck = $this->defaultDeck($candidateDecks);

        if ($defaultDeck !== null) {
            return $defaultDeck;
        }

        if (count($candidateDecks) === 1) {
            return $candidateDecks[0];
        }

        throw $this->unsupportedDeckException(array_map(
            static fn (StudyImportArchiveDeck $deck): string => $deck->name,
            $candidateDecks,
        ));
    }

    /**
     * @param  list<StudyImportArchiveDeck>  $decks
     * @param  array<int, true>  $cardDeckIds
     * @return list<StudyImportArchiveDeck>
     */
    private function candidateDecks(array $decks, array $cardDeckIds): array
    {
        $validDecks = array_values(array_filter(
            $decks,
            static fn (StudyImportArchiveDeck $deck): bool => $deck->name !== '',
        ));

        // If the cards table exists but is empty, keep metadata decks visible so the
        // downstream no-cards branch can report the selected deck name.
        if ($cardDeckIds === []) {
            return $validDecks;
        }

        return array_values(array_filter(
            $validDecks,
            static fn (StudyImportArchiveDeck $deck): bool => isset($cardDeckIds[$deck->sourceDeckId]),
        ));
    }

    /** @param list<StudyImportArchiveDeck> $decks */
    private function assertValidDeckNames(array $decks): void
    {
        foreach ($decks as $deck) {
            if (! mb_check_encoding($deck->name, 'UTF-8')) {
                throw StudyImportPreviewException::invalidDeckNameEncoding();
            }
        }
    }

    /** @param list<StudyImportArchiveDeck> $decks */
    private function defaultDeck(array $decks): ?StudyImportArchiveDeck
    {
        foreach ($decks as $deck) {
            if ($deck->name === StudyImportJob::DEFAULT_DECK_NAME) {
                return $deck;
            }
        }

        return null;
    }

    /**
     * @return list<StudyImportArchiveReviewLog>
     */
    private function fetchTargetDeckReviewLogs(PDO $pdo, int $deckId): array
    {
        if (! $this->hasTable($pdo, 'revlog')) {
            return [];
        }

        $columns = $this->tableColumns($pdo, 'revlog');
        $selects = [
            'r.id AS review_id',
            'r.cid AS card_id',
            $this->nullableIntegerSelect($columns, 'ease'),
            $this->nullableIntegerSelect($columns, 'ivl', 'interval'),
            $this->nullableIntegerSelect($columns, 'lastIvl', 'last_interval'),
            $this->nullableIntegerSelect($columns, 'factor'),
            $this->nullableIntegerSelect($columns, 'time', 'time_ms'),
            $this->nullableIntegerSelect($columns, 'type', 'review_type'),
        ];

        $rows = $this->fetchAll(
            $pdo,
            'SELECT '.implode(', ', $selects).' FROM revlog r JOIN cards c ON c.id = r.cid WHERE c.did = :deck_id ORDER BY r.id ASC',
            ['deck_id' => $deckId],
        );

        return array_map(
            static fn (array $row): StudyImportArchiveReviewLog => new StudyImportArchiveReviewLog(
                sourceReviewId: (int) $row['review_id'],
                sourceCardId: (int) $row['card_id'],
                sourceEase: self::nullableInteger($row['ease']),
                sourceInterval: self::nullableInteger($row['interval']),
                sourceLastInterval: self::nullableInteger($row['last_interval']),
                sourceFactor: self::nullableInteger($row['factor']),
                sourceTimeMs: self::nullableInteger($row['time_ms']),
                sourceReviewType: self::nullableInteger($row['review_type']),
            ),
            $rows,
        );
    }

    /**
     * @param  array<string, true>  $columns
     */
    private function nullableIntegerSelect(array $columns, string $column, ?string $alias = null): string
    {
        $alias ??= $column;

        if (! isset($columns[$column])) {
            return 'NULL AS '.$alias;
        }

        return 'r."'.$column.'" AS '.$alias;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function hasTable(PDO $pdo, string $tableName): bool
    {
        return $this->fetchScalar(
            $pdo,
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1",
            ['table_name' => $tableName],
        ) !== false;
    }

    /**
     * @return array<string, true>
     */
    private function tableColumns(PDO $pdo, string $tableName): array
    {
        $columns = [];

        foreach ($this->fetchAll($pdo, 'SELECT name FROM pragma_table_info(:table_name)', ['table_name' => $tableName]) as $row) {
            $name = $this->columnName($row);

            if ($name !== null) {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function columnName(array $row): ?string
    {
        if (! isset($row['name']) || ! is_string($row['name'])) {
            return null;
        }

        return $row['name'] !== '' ? $row['name'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionMetadata(PDO $pdo): array
    {
        $row = $this->fetchOne($pdo, 'SELECT models, decks FROM col LIMIT 1');

        if ($row === null) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        $decoded = json_decode(str_replace("\0", '', $json), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $detectedDeckNames
     */
    private function unsupportedDeckException(array $detectedDeckNames): StudyImportPreviewException
    {
        $visibleDeckNames = array_slice(array_values(array_filter($detectedDeckNames)), 0, 5);
        $deckSummary = $visibleDeckNames === []
            ? ''
            : ' Found: '.implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', $visibleDeckNames)).'.';

        return new StudyImportPreviewException('Import supports the "'.StudyImportJob::DEFAULT_DECK_NAME.'" deck or archives where exactly one deck contains cards in this version.'.$deckSummary);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchScalar(PDO $pdo, string $sql, array $params = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
