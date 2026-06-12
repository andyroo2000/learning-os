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
        if (! ($options['omit_cards_table'] ?? false)) {
            $pdo->exec('CREATE TABLE cards (id integer primary key, nid integer not null, did integer not null, ord integer not null)');
        }
        if ($options['omit_revlog_table'] ?? false) {
            // Minimal exports from never-reviewed decks can omit revlog entirely.
        } elseif ($options['legacy_revlog_schema'] ?? false) {
            $pdo->exec('CREATE TABLE revlog (id integer primary key, cid integer not null)');
        } else {
            $pdo->exec('CREATE TABLE revlog (id integer primary key, cid integer not null, ease integer not null, ivl integer not null, lastIvl integer not null, factor integer not null, time integer not null, type integer not null)');
        }

        $deckId = $options['deck_id'] ?? 1700000000000;
        $basicNoteTypeId = 1001;
        $clozeNoteTypeId = 1002;
        $deckName = $options['deck_name'] ?? StudyImportJob::DEFAULT_DECK_NAME;
        $cardDeckId = $options['card_deck_id'] ?? $deckId;
        $extraDecks = $options['extra_decks'] ?? [];
        $extraCards = $options['extra_cards'] ?? [];
        $fieldSeparator = "\x1f";
        $noteOneFields = $options['note_one_fields'] ?? '会社[sound:word.mp3]'.$fieldSeparator.'<img src="company.png"> company';

        $models = [
            (string) $basicNoteTypeId => [
                'id' => $basicNoteTypeId,
                'name' => 'Basic',
                'flds' => [
                    ['name' => 'Front'],
                    ['name' => 'Back'],
                ],
                'tmpls' => [
                    [
                        'name' => 'Card 1',
                        'ord' => 0,
                        'qfmt' => '{{Front}}',
                        'afmt' => '{{FrontSide}}<hr id="answer">{{Back}}',
                    ],
                    [
                        'name' => 'Card 2',
                        'ord' => 1,
                        'qfmt' => '{{Back}}',
                        'afmt' => '{{FrontSide}}<hr id="answer">{{Front}}',
                    ],
                ],
            ],
            (string) $clozeNoteTypeId => [
                'id' => $clozeNoteTypeId,
                'name' => 'Cloze',
                'flds' => [
                    ['name' => 'Text'],
                ],
                'tmpls' => [
                    [
                        'name' => 'Cloze',
                        'ord' => 0,
                        'qfmt' => '{{cloze:Text}}',
                        'afmt' => '{{cloze:Text}}',
                    ],
                ],
            ],
        ];
        $decks = [
            (string) $deckId => [
                'id' => $deckId,
                'name' => $deckName,
            ],
        ];
        foreach ($extraDecks as $extraDeck) {
            if (! is_array($extraDeck)) {
                continue;
            }

            $extraDeckId = $extraDeck['id'] ?? null;

            if (! is_numeric($extraDeckId)) {
                continue;
            }

            $decks[(string) $extraDeckId] = [
                'id' => (int) $extraDeckId,
                'name' => $extraDeck['name'] ?? '',
            ];
        }

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
            foreach ($extraDecks as $extraDeck) {
                if (! is_array($extraDeck) || ! isset($extraDeck['id']) || ! is_numeric($extraDeck['id'])) {
                    continue;
                }

                $statement->execute([
                    'id' => (int) $extraDeck['id'],
                    'name' => (string) ($extraDeck['name'] ?? ''),
                ]);
            }

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

        if (! ($options['omit_cards_table'] ?? false)) {
            $statement = $pdo->prepare('INSERT INTO cards (id, nid, did, ord) VALUES (:id, :nid, :did, :ord)');
            $statement->execute(['id' => 701, 'nid' => 501, 'did' => $cardDeckId, 'ord' => 0]);
            $statement->execute(['id' => 702, 'nid' => 501, 'did' => $cardDeckId, 'ord' => 1]);
            $statement->execute(['id' => 703, 'nid' => 502, 'did' => $cardDeckId, 'ord' => 0]);
            foreach ($extraCards as $extraCard) {
                if (! is_array($extraCard)) {
                    continue;
                }

                $statement->execute([
                    'id' => $extraCard['id'] ?? 704,
                    'nid' => $extraCard['nid'] ?? 501,
                    'did' => $extraCard['did'] ?? 1700000000001,
                    'ord' => $extraCard['ord'] ?? 0,
                ]);
            }
        }

        if ($options['omit_revlog_table'] ?? false) {
            return;
        }

        $reviewLogs = $options['review_logs'] ?? [
            ['id' => 1700000000123, 'cid' => 701, 'ease' => 3, 'ivl' => 12, 'lastIvl' => 6, 'factor' => 2500, 'time' => 980, 'type' => 1],
            ['id' => 1700000000456, 'cid' => 703, 'ease' => 4, 'ivl' => 21, 'lastIvl' => 12, 'factor' => 2600, 'time' => 760, 'type' => 1],
        ];

        if ($options['legacy_revlog_schema'] ?? false) {
            $statement = $pdo->prepare('INSERT INTO revlog (id, cid) VALUES (:id, :cid)');
            foreach ($reviewLogs as $reviewLog) {
                $statement->execute([
                    'id' => $reviewLog['id'],
                    'cid' => $reviewLog['cid'],
                ]);
            }
        } else {
            $statement = $pdo->prepare('INSERT INTO revlog (id, cid, ease, ivl, lastIvl, factor, time, type) VALUES (:id, :cid, :ease, :ivl, :lastIvl, :factor, :time, :type)');
            foreach ($reviewLogs as $reviewLog) {
                $statement->execute([
                    'id' => $reviewLog['id'],
                    'cid' => $reviewLog['cid'],
                    'ease' => $reviewLog['ease'],
                    'ivl' => $reviewLog['ivl'],
                    'lastIvl' => $reviewLog['lastIvl'],
                    'factor' => $reviewLog['factor'],
                    'time' => $reviewLog['time'],
                    'type' => $reviewLog['type'],
                ]);
            }
        }
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
