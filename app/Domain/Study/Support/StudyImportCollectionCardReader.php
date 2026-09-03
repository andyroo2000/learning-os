<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Exceptions\StudyImportPreviewException;
use PDO;

final class StudyImportCollectionCardReader
{
    public function __construct(
        private readonly StudyImportArchiveTemplateRenderer $templateRenderer,
    ) {}

    /**
     * @return list<StudyImportArchiveCard>
     */
    public function read(PDO $pdo, int $deckId): array
    {
        $noteTypes = $this->noteTypesById($pdo);
        $rows = $this->cardRows($pdo, $deckId);

        $this->assertValidNoteTypeNames($rows, $noteTypes);

        return array_map(
            fn (array $row): StudyImportArchiveCard => $this->cardFromRow($row, $noteTypes),
            $rows,
        );
    }

    /**
     * @return array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>
     */
    private function noteTypesById(PDO $pdo): array
    {
        if ($this->hasTable($pdo, 'notetypes')) {
            return $this->normalizedNoteTypesById($pdo);
        }

        return $this->legacyNoteTypesById($pdo);
    }

    /**
     * @return array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>
     */
    private function normalizedNoteTypesById(PDO $pdo): array
    {
        $noteTypes = [];

        foreach ($this->fetchAll($pdo, 'SELECT id, name FROM notetypes') as $row) {
            if (! $this->hasNumericIdAndStringName($row)) {
                continue;
            }

            // Normalized Anki schemas split fields/templates into separate tables.
            // Querying them is deferred; renderer falls back to positional fields.
            $noteTypes[(int) $row['id']] = [
                'name' => $this->cleanString($row['name']),
                'fields' => [],
                'templates' => [],
            ];
        }

        return $noteTypes;
    }

    /**
     * @return array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>
     */
    private function legacyNoteTypesById(PDO $pdo): array
    {
        $collectionRow = $this->collectionMetadata($pdo);
        $models = $this->decodeJsonObject((string) ($collectionRow['models'] ?? '{}'));
        $noteTypes = [];

        foreach ($models as $model) {
            if (! $this->isLegacyNoteType($model)) {
                continue;
            }

            $noteTypes[(int) $model['id']] = $this->legacyNoteType($model);
        }

        return $noteTypes;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}
     */
    private function legacyNoteType(array $model): array
    {
        return [
            'name' => $this->optionalTrimmedString($model, 'name'),
            'fields' => $this->noteTypeFieldNames($model),
            'templates' => $this->noteTypeTemplates($model),
        ];
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
            fn (mixed $field): string => is_array($field) ? $this->optionalTrimmedString($field, 'name') : '',
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
                'name' => $this->optionalTrimmedString($template, 'name'),
                'front' => $this->optionalString($template, 'qfmt'),
                'back' => $this->optionalString($template, 'afmt'),
            ];
        }

        ksort($templatesByOrdinal);

        return $templatesByOrdinal;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardRows(PDO $pdo, int $deckId): array
    {
        return $this->fetchAll(
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
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>  $noteTypes
     */
    private function assertValidNoteTypeNames(array $rows, array $noteTypes): void
    {
        $usedNoteTypeIds = [];

        foreach ($rows as $row) {
            $usedNoteTypeIds[(int) $row['note_type_id']] = true;
        }

        foreach (array_keys($usedNoteTypeIds) as $noteTypeId) {
            $this->assertValidNoteTypeName($noteTypes[$noteTypeId]['name'] ?? '');
        }
    }

    private function assertValidNoteTypeName(string $name): void
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            throw StudyImportPreviewException::invalidNoteTypeNameEncoding();
        }

        if (mb_strlen($name) > Card::MAX_SOURCE_NOTETYPE_NAME_LENGTH) {
            throw StudyImportPreviewException::noteTypeNameTooLong(Card::MAX_SOURCE_NOTETYPE_NAME_LENGTH);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}>  $noteTypes
     */
    private function cardFromRow(array $row, array $noteTypes): StudyImportArchiveCard
    {
        $noteTypeId = (int) $row['note_type_id'];
        $templateOrdinal = (int) $row['template_ord'];
        $noteFields = is_string($row['note_fields']) ? $this->stripNullBytes($row['note_fields']) : '';
        $noteType = $noteTypes[$noteTypeId] ?? $this->emptyNoteType();

        if (! mb_check_encoding($noteFields, 'UTF-8')) {
            throw StudyImportPreviewException::invalidCardTextEncoding();
        }

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
            frontMediaReferences: $renderedText['front_media_references'],
        );
    }

    /**
     * @return array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}
     */
    private function emptyNoteType(): array
    {
        return ['name' => '', 'fields' => [], 'templates' => []];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function hasNumericIdAndStringName(array $values): bool
    {
        return isset($values['id'])
            && is_numeric($values['id'])
            && isset($values['name'])
            && is_string($values['name']);
    }

    private function isLegacyNoteType(mixed $model): bool
    {
        if (! is_array($model)) {
            return false;
        }

        if (! isset($model['id'])) {
            return false;
        }

        return is_numeric($model['id']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function optionalTrimmedString(array $values, string $key): string
    {
        return isset($values[$key]) && is_string($values[$key])
            ? $this->cleanString($values[$key])
            : '';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function optionalString(array $values, string $key): string
    {
        return isset($values[$key]) && is_string($values[$key])
            ? $this->stripNullBytes($values[$key])
            : '';
    }

    private function cleanString(string $value): string
    {
        return trim($this->stripNullBytes($value));
    }

    private function stripNullBytes(string $value): string
    {
        return str_replace("\0", '', $value);
    }

    private function hasTable(PDO $pdo, string $tableName): bool
    {
        $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
        $statement->execute(['table_name' => $tableName]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionMetadata(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT models, decks FROM col LIMIT 1');
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row)) {
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

        return is_array($decoded) ? $decoded : [];
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
}
