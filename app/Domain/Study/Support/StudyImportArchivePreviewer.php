<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Filesystem\FilesystemAdapter;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use ZipArchive;

final class StudyImportArchivePreviewer
{
    private const COLLECTION_DATABASE_ENTRIES = [
        'collection.anki21b',
        'collection.anki21',
        'collection.anki2',
    ];

    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";

    private const FIELD_SEPARATOR = "\x1f";

    /**
     * @return array<string, mixed>
     */
    public function preview(FilesystemAdapter $disk, string $sourceObjectPath): array
    {
        $archivePath = $this->copyStorageObjectToTempFile($disk, $sourceObjectPath);
        $collectionPath = null;

        try {
            $collectionPath = $this->extractCollectionDatabase($archivePath);

            return $this->previewFromCollectionDatabase($collectionPath);
        } finally {
            @unlink($archivePath);

            if ($collectionPath !== null) {
                @unlink($collectionPath);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function previewFromCollectionDatabase(string $collectionPath): array
    {
        try {
            $pdo = new PDO('sqlite:'.$collectionPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $deckId = $this->targetDeckId($pdo);
            $noteTypeNames = $this->noteTypeNamesById($pdo);
            $cardRows = $this->fetchTargetDeckCards($pdo, $deckId);

            if ($cardRows === []) {
                throw new StudyImportPreviewException('Deck "'.StudyImportJob::DEFAULT_DECK_NAME.'" has no cards to import.');
            }

            $reviewLogCount = $this->countTargetDeckReviewLogs($pdo, $deckId);

            return [
                'deck_name' => StudyImportJob::DEFAULT_DECK_NAME,
                'card_count' => count($cardRows),
                'note_count' => count(array_unique(array_column($cardRows, 'note_id'))),
                'review_log_count' => $reviewLogCount,
                'media_reference_count' => count($this->mediaReferences($cardRows)),
                'skipped_media_count' => 0,
                'warnings' => [],
                'note_type_breakdown' => $this->noteTypeBreakdown($cardRows, $noteTypeNames),
            ];
        } catch (StudyImportPreviewException $exception) {
            throw $exception;
        } catch (PDOException|JsonException|RuntimeException $exception) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }
    }

    private function copyStorageObjectToTempFile(FilesystemAdapter $disk, string $sourceObjectPath): string
    {
        $input = $disk->readStream($sourceObjectPath);

        if ($input === false || $input === null) {
            throw StudyImportPreviewException::missingCollectionDatabase();
        }

        $tempPath = $this->tempPath('study-import-archive-');
        $output = fopen($tempPath, 'wb');

        if ($output === false) {
            fclose($input);

            throw new RuntimeException('Unable to create a temporary import archive file.');
        }

        try {
            stream_copy_to_stream($input, $output);

            return $tempPath;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function extractCollectionDatabase(string $archivePath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        try {
            foreach (self::COLLECTION_DATABASE_ENTRIES as $entryName) {
                $stream = $zip->getStream($entryName);

                if ($stream === false) {
                    continue;
                }

                try {
                    return $this->copyCollectionStreamToTempFile($stream);
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }

        throw StudyImportPreviewException::missingCollectionDatabase();
    }

    /**
     * @param  resource  $stream
     */
    private function copyCollectionStreamToTempFile($stream): string
    {
        $collectionPath = $this->tempPath('study-import-collection-');
        $output = fopen($collectionPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to create a temporary collection database file.');
        }

        try {
            $header = fread($stream, 4);

            if ($header === false) {
                throw StudyImportPreviewException::invalidCollectionDatabase();
            }

            if ($header === self::ZSTD_MAGIC) {
                throw StudyImportPreviewException::unsupportedCompressedCollectionDatabase();
            }

            fwrite($output, $header);
            stream_copy_to_stream($stream, $output);

            return $collectionPath;
        } catch (StudyImportPreviewException $exception) {
            @unlink($collectionPath);

            throw $exception;
        } finally {
            fclose($output);
        }
    }

    private function targetDeckId(PDO $pdo): int
    {
        if ($this->hasTable($pdo, 'decks')) {
            $deckId = $this->fetchScalar(
                $pdo,
                'SELECT id FROM decks WHERE name = :deck_name LIMIT 1',
                ['deck_name' => StudyImportJob::DEFAULT_DECK_NAME],
            );

            if (is_numeric($deckId)) {
                return (int) $deckId;
            }

            throw $this->unsupportedDeckException($this->fetchColumn($pdo, 'SELECT name FROM decks'));
        }

        $collectionRow = $this->collectionMetadata($pdo);
        $decks = $this->decodeJsonObject((string) ($collectionRow['decks'] ?? '{}'));
        $detectedDeckNames = [];

        foreach ($decks as $deck) {
            if (! is_array($deck)) {
                continue;
            }

            $deckName = isset($deck['name']) && is_string($deck['name']) ? $deck['name'] : '';

            if ($deckName !== '') {
                $detectedDeckNames[] = $deckName;
            }

            if ($deckName === StudyImportJob::DEFAULT_DECK_NAME && isset($deck['id']) && is_numeric($deck['id'])) {
                return (int) $deck['id'];
            }
        }

        throw $this->unsupportedDeckException($detectedDeckNames);
    }

    /**
     * @return array<int, string>
     */
    private function noteTypeNamesById(PDO $pdo): array
    {
        if ($this->hasTable($pdo, 'notetypes')) {
            $rows = $this->fetchAll($pdo, 'SELECT id, name FROM notetypes');

            return array_reduce(
                $rows,
                static function (array $names, array $row): array {
                    if (isset($row['id']) && is_numeric($row['id']) && isset($row['name']) && is_string($row['name'])) {
                        $names[(int) $row['id']] = trim(str_replace("\0", '', $row['name']));
                    }

                    return $names;
                },
                [],
            );
        }

        $collectionRow = $this->collectionMetadata($pdo);
        $models = $this->decodeJsonObject((string) ($collectionRow['models'] ?? '{}'));
        $names = [];

        foreach ($models as $model) {
            if (! is_array($model) || ! isset($model['id']) || ! is_numeric($model['id'])) {
                continue;
            }

            $names[(int) $model['id']] = isset($model['name']) && is_string($model['name'])
                ? trim(str_replace("\0", '', $model['name']))
                : '';
        }

        return $names;
    }

    /**
     * @return list<array{card_id: int, note_id: int, note_type_id: int, note_fields: string}>
     */
    private function fetchTargetDeckCards(PDO $pdo, int $deckId): array
    {
        $rows = $this->fetchAll(
            $pdo,
            <<<'SQL'
                SELECT
                    c.id AS card_id,
                    n.id AS note_id,
                    n.mid AS note_type_id,
                    n.flds AS note_fields
                FROM cards c
                JOIN notes n ON n.id = c.nid
                WHERE c.did = :deck_id
                ORDER BY c.id ASC
                SQL,
            ['deck_id' => $deckId],
        );

        return array_map(
            static fn (array $row): array => [
                'card_id' => (int) $row['card_id'],
                'note_id' => (int) $row['note_id'],
                'note_type_id' => (int) $row['note_type_id'],
                'note_fields' => is_string($row['note_fields']) ? str_replace("\0", '', $row['note_fields']) : '',
            ],
            $rows,
        );
    }

    private function countTargetDeckReviewLogs(PDO $pdo, int $deckId): int
    {
        return (int) $this->fetchScalar(
            $pdo,
            <<<'SQL'
                SELECT COUNT(*)
                FROM revlog r
                JOIN cards c ON c.id = r.cid
                WHERE c.did = :deck_id
                SQL,
            ['deck_id' => $deckId],
        );
    }

    /**
     * @param  list<array{card_id: int, note_id: int, note_type_id: int, note_fields: string}>  $cardRows
     * @return list<array{note_type_name: string, note_count: int, card_count: int}>
     */
    private function noteTypeBreakdown(array $cardRows, array $noteTypeNames): array
    {
        $breakdown = [];

        foreach ($cardRows as $row) {
            $noteTypeId = $row['note_type_id'];
            $noteTypeName = $noteTypeNames[$noteTypeId] ?? '';
            $noteTypeName = $noteTypeName !== '' ? $noteTypeName : 'Unknown';

            $breakdown[$noteTypeName] ??= [
                'note_type_name' => $noteTypeName,
                'note_ids' => [],
                'card_count' => 0,
            ];

            $breakdown[$noteTypeName]['note_ids'][$row['note_id']] = true;
            $breakdown[$noteTypeName]['card_count']++;
        }

        return array_values(array_map(
            static fn (array $item): array => [
                'note_type_name' => $item['note_type_name'],
                'note_count' => count($item['note_ids']),
                'card_count' => $item['card_count'],
            ],
            $breakdown,
        ));
    }

    /**
     * @param  list<array{card_id: int, note_id: int, note_type_id: int, note_fields: string}>  $cardRows
     * @return list<string>
     */
    private function mediaReferences(array $cardRows): array
    {
        $mediaReferences = [];

        foreach ($cardRows as $row) {
            foreach (explode(self::FIELD_SEPARATOR, $row['note_fields']) as $fieldValue) {
                foreach ($this->extractMediaReferences($fieldValue) as $filename) {
                    $mediaReferences[$filename] = true;
                }
            }
        }

        return array_keys($mediaReferences);
    }

    /**
     * @return list<string>
     */
    private function extractMediaReferences(string $value): array
    {
        $references = [];

        preg_match_all('/\[sound:([^\]\r\n]+)\]/i', $value, $soundMatches);
        foreach ($soundMatches[1] ?? [] as $filename) {
            $references[] = trim(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\']?)([^"\'\s>]+)\1/i', $value, $imageMatches);
        foreach ($imageMatches[2] ?? [] as $filename) {
            $references[] = trim(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return array_values(array_filter(
            $references,
            static fn (string $filename): bool => $filename !== '',
        ));
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

        return new StudyImportPreviewException('Only the "'.StudyImportJob::DEFAULT_DECK_NAME.'" deck is supported in this version.'.$deckSummary);
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

    /**
     * @return list<string>
     */
    private function fetchColumn(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN),
            static fn (mixed $value): bool => is_string($value),
        ));
    }

    private function tempPath(string $prefix): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary study import file.');
        }

        return $tempPath;
    }
}
