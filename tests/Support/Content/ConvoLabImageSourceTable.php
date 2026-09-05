<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabImageSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('Image', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->text('url')->nullable();
            $table->text('prompt')->nullable();
            $table->integer('order');
            $table->string('sentenceStartId')->nullable();
            $table->string('sentenceEndId')->nullable();
            $table->dateTime('createdAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('Image')->insert([
            'id' => $ids['image'], 'episodeId' => $ids['dialogueEpisode'],
            'url' => '/uploads/episode-images/dialogue.png', 'prompt' => 'A cat', 'order' => 1,
            'sentenceStartId' => $ids['sentence'], 'sentenceEndId' => $ids['sentence'],
            'createdAt' => $created,
        ]);
    }
}
