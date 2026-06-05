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

final class StudyImportArchiveReader
{
    private const COLLECTION_DATABASE_ENTRIES = [
        'collection.anki21b',
        'collection.anki21',
        'collection.anki2',
    ];

    private const ZSTD_MAGIC = "\x28\xb5\x2f\xfd";

    public function __construct(
        private readonly StudyImportArchiveTemplateRenderer $templateRenderer,
    ) {}

    public function read(FilesystemAdapter $disk, string $sourceObjectPath): StudyImportArchiveRead
    {
        $archivePath = $this->copyStorageObjectToTempFile($disk, $sourceObjectPath);
        $collectionPath = null;
        $zip = null;

        try {
            $zip = $this->openArchive($archivePath);
            $collectionPath = $this->extractCollectionDatabase($zip);

            return $this->readFromCollectionDatabase(
                $collectionPath,
                $this->mediaManifestByFilename($zip),
            );
        } finally {
            $zip?->close();
            @unlink($archivePath);

            if ($collectionPath !== null) {
                @unlink($collectionPath);
            }
        }
    }

    /**
     * @param  array<string, string>  $targetPathsBySourceMediaRef
     * @return array<string, bool>
     */
    public function copyMediaEntriesToDisk(
        FilesystemAdapter $sourceDisk,
        string $sourceObjectPath,
        FilesystemAdapter $targetDisk,
        array $targetPathsBySourceMediaRef,
    ): array {
        if ($targetPathsBySourceMediaRef === []) {
            return [];
        }

        $archivePath = $this->copyStorageObjectToTempFile($sourceDisk, $sourceObjectPath);
        $zip = null;

        try {
            $zip = $this->openArchive($archivePath);
            $copied = [];

            foreach ($targetPathsBySourceMediaRef as $sourceMediaRef => $targetPath) {
                $stream = $zip->getStream((string) $sourceMediaRef);

                if ($stream === false) {
                    $copied[(string) $sourceMediaRef] = false;

                    continue;
                }

                try {
                    $copied[(string) $sourceMediaRef] = $targetDisk->put($targetPath, $stream);
                } finally {
                    fclose($stream);
                }
            }

            return $copied;
        } finally {
            $zip?->close();
            @unlink($archivePath);
        }
    }

    /**
     * @param  array<string, StudyImportArchiveMediaEntry>  $mediaManifestByFilename
     */
    private function readFromCollectionDatabase(string $collectionPath, array $mediaManifestByFilename): StudyImportArchiveRead
    {
        try {
            $pdo = new PDO('sqlite:'.$collectionPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $deckId = $this->targetDeckId($pdo);
            $noteTypes = $this->noteTypesById($pdo);
            $cards = $this->fetchTargetDeckCards($pdo, $deckId, $noteTypes);

            if ($cards === []) {
                throw new StudyImportPreviewException('Deck "'.StudyImportJob::DEFAULT_DECK_NAME.'" has no cards to import.');
            }

            return new StudyImportArchiveRead(
                deckName: StudyImportJob::DEFAULT_DECK_NAME,
                cards: $cards,
                reviewLogs: $this->fetchTargetDeckReviewLogs($pdo, $deckId),
                mediaManifestByFilename: $mediaManifestByFilename,
            );
        } catch (StudyImportPreviewException $exception) {
            throw $exception;
        } catch (PDOException|JsonException|RuntimeException) {
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

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw StudyImportPreviewException::invalidCollectionDatabase();
        }

        return $zip;
    }

    private function extractCollectionDatabase(ZipArchive $zip): string
    {
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

        throw StudyImportPreviewException::missingCollectionDatabase();
    }

    /**
     * @return array<string, StudyImportArchiveMediaEntry>
     */
    private function mediaManifestByFilename(ZipArchive $zip): array
    {
        $stream = $zip->getStream('media');

        if ($stream === false) {
            return [];
        }

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            try {
                $decoded = json_decode(str_replace("\0", '', $contents), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw StudyImportPreviewException::invalidMediaManifest();
            }

            if (! is_array($decoded)) {
                return [];
            }

            $manifest = [];

            foreach ($decoded as $sourceMediaRef => $filename) {
                if (! is_string($filename) || str_contains($filename, "\0")) {
                    continue;
                }

                $filename = trim($filename);

                if ($filename === '' || array_key_exists($filename, $manifest)) {
                    continue;
                }

                $sourceMediaRef = (string) $sourceMediaRef;
                $contentMetadata = $this->mediaContentMetadata($zip, $sourceMediaRef);

                $manifest[$filename] = new StudyImportArchiveMediaEntry(
                    sourceMediaRef: $sourceMediaRef,
                    sourceFilename: $filename,
                    hasContent: $contentMetadata['has_content'],
                    sizeBytes: $contentMetadata['size_bytes'],
                    checksumSha256: $contentMetadata['checksum_sha256'],
                );
            }

            return $manifest;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{has_content: bool, size_bytes: int|null, checksum_sha256: string|null}
     */
    private function mediaContentMetadata(ZipArchive $zip, string $sourceMediaRef): array
    {
        $index = $zip->locateName($sourceMediaRef);

        if ($index === false) {
            return [
                'has_content' => false,
                'size_bytes' => null,
                'checksum_sha256' => null,
            ];
        }

        $stream = $zip->getStream($sourceMediaRef);

        if ($stream === false) {
            return [
                'has_content' => false,
                'size_bytes' => null,
                'checksum_sha256' => null,
            ];
        }

        try {
            $hashContext = hash_init('sha256');
            hash_update_stream($hashContext, $stream);
            $stat = $zip->statIndex($index);

            return [
                'has_content' => true,
                'size_bytes' => is_array($stat) && isset($stat['size']) && is_numeric($stat['size'])
                    ? (int) $stat['size']
                    : null,
                'checksum_sha256' => hash_final($hashContext),
            ];
        } finally {
            fclose($stream);
        }
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
     * @return array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>
     */
    private function noteTypesById(PDO $pdo): array
    {
        if ($this->hasTable($pdo, 'notetypes')) {
            $rows = $this->fetchAll($pdo, 'SELECT id, name FROM notetypes');

            return array_reduce(
                $rows,
                static function (array $noteTypes, array $row): array {
                    if (isset($row['id']) && is_numeric($row['id']) && isset($row['name']) && is_string($row['name'])) {
                        // Normalized Anki schemas split fields/templates into separate tables.
                        // Querying them is deferred; renderer falls back to positional fields.
                        $noteTypes[(int) $row['id']] = [
                            'name' => trim(str_replace("\0", '', $row['name'])),
                            'fields' => [],
                            'templates' => [],
                        ];
                    }

                    return $noteTypes;
                },
                [],
            );
        }

        $collectionRow = $this->collectionMetadata($pdo);
        $models = $this->decodeJsonObject((string) ($collectionRow['models'] ?? '{}'));
        $noteTypes = [];

        foreach ($models as $model) {
            if (! is_array($model) || ! isset($model['id']) || ! is_numeric($model['id'])) {
                continue;
            }

            $noteTypes[(int) $model['id']] = [
                'name' => isset($model['name']) && is_string($model['name'])
                    ? trim(str_replace("\0", '', $model['name']))
                    : '',
                'fields' => $this->noteTypeFieldNames($model),
                'templates' => $this->noteTypeTemplates($model),
            ];
        }

        return $noteTypes;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return list<string>
     */
    private function noteTypeFieldNames(array $model): array
    {
        $fields = $model['flds'] ?? [];

        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $field): string => is_array($field) && isset($field['name']) && is_string($field['name'])
                ? trim(str_replace("\0", '', $field['name']))
                : '',
            $fields,
        ));
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<int, array{name: string, front: string, back: string}>
     */
    private function noteTypeTemplates(array $model): array
    {
        $templates = $model['tmpls'] ?? [];

        if (! is_array($templates)) {
            return [];
        }

        $templatesByOrdinal = [];

        foreach ($templates as $index => $template) {
            if (! is_array($template)) {
                continue;
            }

            $ordinal = isset($template['ord']) && is_numeric($template['ord'])
                ? (int) $template['ord']
                : (int) $index;

            $templatesByOrdinal[$ordinal] = [
                'name' => isset($template['name']) && is_string($template['name'])
                    ? trim(str_replace("\0", '', $template['name']))
                    : '',
                'front' => isset($template['qfmt']) && is_string($template['qfmt'])
                    ? str_replace("\0", '', $template['qfmt'])
                    : '',
                'back' => isset($template['afmt']) && is_string($template['afmt'])
                    ? str_replace("\0", '', $template['afmt'])
                    : '',
            ];
        }

        ksort($templatesByOrdinal);

        return $templatesByOrdinal;
    }

    /**
     * @param  array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>  $noteTypes
     * @return list<StudyImportArchiveCard>
     */
    private function fetchTargetDeckCards(PDO $pdo, int $deckId, array $noteTypes): array
    {
        $rows = $this->fetchAll(
            $pdo,
            <<<'SQL'
                SELECT
                    c.id AS card_id,
                    c.did AS deck_id,
                    c.ord AS template_ord,
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
            function (array $row) use ($noteTypes): StudyImportArchiveCard {
                $noteTypeId = (int) $row['note_type_id'];
                $templateOrdinal = (int) $row['template_ord'];
                $noteFields = is_string($row['note_fields']) ? str_replace("\0", '', $row['note_fields']) : '';
                $noteType = $noteTypes[$noteTypeId] ?? [
                    'name' => '',
                    'fields' => [],
                    'templates' => [],
                ];
                $renderedText = $this->templateRenderer->render($noteType, $templateOrdinal, $noteFields);

                return new StudyImportArchiveCard(
                    sourceCardId: (int) $row['card_id'],
                    sourceNoteId: (int) $row['note_id'],
                    sourceDeckId: (int) $row['deck_id'],
                    sourceNoteTypeId: $noteTypeId,
                    sourceNoteTypeName: $noteType['name'],
                    sourceTemplateOrdinal: $templateOrdinal,
                    frontText: $renderedText['front'],
                    backText: $renderedText['back'],
                    noteFields: $noteFields,
                );
            },
            $rows,
        );
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
            if (isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
                $columns[$row['name']] = true;
            }
        }

        return $columns;
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
