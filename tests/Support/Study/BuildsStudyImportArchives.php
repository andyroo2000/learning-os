<?php

namespace Tests\Support\Study;

use App\Domain\Study\Models\StudyImportJob;
use PDO;
use RuntimeException;
use ZipArchive;

trait BuildsStudyImportArchives
{
    protected function buildStudyImportArchiveBytes(array $options = []): string
    {
        $databasePath = $this->tempStudyImportPath('study-import-fixture-db-');
        $archivePath = $this->tempStudyImportPath('study-import-fixture-archive-');

        try {
            $this->createStudyImportCollectionDatabase($databasePath, $options);
            $mediaMap = $options['media_map'] ?? [
                '0' => 'word.mp3',
                '1' => 'company.png',
            ];
            $mediaEntries = $options['media_entries'] ?? array_fill_keys(array_keys($mediaMap), 'media-bytes');
            $entries = [
                $options['collection_entry'] ?? 'collection.anki21' => file_get_contents($databasePath),
                'media' => $options['media_contents'] ?? json_encode($mediaMap, JSON_THROW_ON_ERROR),
            ];

            foreach ($mediaEntries as $sourceMediaRef => $contents) {
                $entries[(string) $sourceMediaRef] = $contents;
            }

            $this->createStudyImportZip($archivePath, $entries);

            return (string) file_get_contents($archivePath);
        } finally {
            @unlink($databasePath);
            @unlink($archivePath);
        }
    }

    protected function buildStudyImportZipBytes(array $entries): string
    {
        $archivePath = $this->tempStudyImportPath('study-import-fixture-archive-');

        try {
            $this->createStudyImportZip($archivePath, $entries);

            return (string) file_get_contents($archivePath);
        } finally {
            @unlink($archivePath);
        }
    }

    private function createStudyImportCollectionDatabase(string $databasePath, array $options): void
    {
        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE col (id integer primary key, models text not null, decks text not null)');
        $pdo->exec('CREATE TABLE notes (id integer primary key, guid text not null, mid integer not null, flds text not null)');
        $pdo->exec('CREATE TABLE cards (id integer primary key, nid integer not null, did integer not null, ord integer not null)');
        $pdo->exec('CREATE TABLE revlog (id integer primary key, cid integer not null)');

        $deckId = 1700000000000;
        $basicNoteTypeId = 1001;
        $clozeNoteTypeId = 1002;
        $deckName = $options['deck_name'] ?? StudyImportJob::DEFAULT_DECK_NAME;
        $fieldSeparator = "\x1f";
        $noteOneFields = $options['note_one_fields'] ?? '会社[sound:word.mp3]'.$fieldSeparator.'<img src="company.png">';

        $models = [
            (string) $basicNoteTypeId => [
                'id' => $basicNoteTypeId,
                'name' => 'Basic',
            ],
            (string) $clozeNoteTypeId => [
                'id' => $clozeNoteTypeId,
                'name' => 'Cloze',
            ],
        ];
        $decks = [
            (string) $deckId => [
                'id' => $deckId,
                'name' => $deckName,
            ],
        ];

        $statement = $pdo->prepare('INSERT INTO col (id, models, decks) VALUES (1, :models, :decks)');
        $statement->execute([
            'models' => json_encode($models, JSON_THROW_ON_ERROR),
            'decks' => json_encode($decks, JSON_THROW_ON_ERROR),
        ]);

        if ($options['normalized_schema'] ?? false) {
            $pdo->exec('CREATE TABLE decks (id integer primary key, name text not null)');
            $pdo->exec('CREATE TABLE notetypes (id integer primary key, name text not null)');

            $statement = $pdo->prepare('INSERT INTO decks (id, name) VALUES (:id, :name)');
            $statement->execute(['id' => $deckId, 'name' => $deckName]);

            $statement = $pdo->prepare('INSERT INTO notetypes (id, name) VALUES (:id, :name)');
            $statement->execute(['id' => $basicNoteTypeId, 'name' => 'Basic']);
            $statement->execute(['id' => $clozeNoteTypeId, 'name' => 'Cloze']);
        }

        $statement = $pdo->prepare('INSERT INTO notes (id, guid, mid, flds) VALUES (:id, :guid, :mid, :flds)');
        $statement->execute([
            'id' => 501,
            'guid' => 'note-one',
            'mid' => $basicNoteTypeId,
            'flds' => $noteOneFields,
        ]);
        $statement->execute([
            'id' => 502,
            'guid' => 'note-two',
            'mid' => $clozeNoteTypeId,
            'flds' => '{{c1::漢字}}',
        ]);

        $statement = $pdo->prepare('INSERT INTO cards (id, nid, did, ord) VALUES (:id, :nid, :did, :ord)');
        $statement->execute(['id' => 701, 'nid' => 501, 'did' => $deckId, 'ord' => 0]);
        $statement->execute(['id' => 702, 'nid' => 501, 'did' => $deckId, 'ord' => 1]);
        $statement->execute(['id' => 703, 'nid' => 502, 'did' => $deckId, 'ord' => 0]);

        $statement = $pdo->prepare('INSERT INTO revlog (id, cid) VALUES (:id, :cid)');
        $statement->execute(['id' => 901, 'cid' => 701]);
        $statement->execute(['id' => 902, 'cid' => 703]);
    }

    private function createStudyImportZip(string $archivePath, array $entries): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create study import test archive.');
        }

        try {
            foreach ($entries as $entryName => $contents) {
                $zip->addFromString((string) $entryName, (string) $contents);
            }
        } finally {
            $zip->close();
        }
    }

    private function tempStudyImportPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary study import fixture.');
        }

        return $path;
    }
}
