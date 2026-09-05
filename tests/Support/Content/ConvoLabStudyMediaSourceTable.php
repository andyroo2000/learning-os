<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabStudyMediaSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('study_media', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('sourceKind');
            $table->string('sourceFilename');
            $table->string('normalizedFilename');
            $table->string('mediaKind');
            $table->string('contentType')->nullable();
            $table->text('storagePath')->nullable();
            $table->text('publicUrl')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('study_media')->insert([
            [
                'id' => $ids['media'], 'userId' => $ids['user'], 'sourceKind' => 'generated',
                'sourceFilename' => 'scene.png', 'normalizedFilename' => 'scene.png', 'mediaKind' => 'image',
                'contentType' => 'image/png', 'storagePath' => 'episode-images/scene.png',
                'publicUrl' => '/uploads/episode-images/scene.png', 'createdAt' => $created, 'updatedAt' => $created,
            ],
            [
                'id' => $ids['unreferencedMedia'], 'userId' => $ids['user'], 'sourceKind' => 'anki_import',
                'sourceFilename' => 'card.png', 'normalizedFilename' => 'card.png', 'mediaKind' => 'image',
                'contentType' => 'image/png', 'storagePath' => 'study-media/card.png',
                'publicUrl' => '/uploads/study-media/card.png', 'createdAt' => $created, 'updatedAt' => $created,
            ],
        ]);
    }
}
