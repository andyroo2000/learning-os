<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabEpisodeSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('Episode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('title');
            $table->text('sourceText');
            $table->string('targetLanguage');
            $table->string('nativeLanguage');
            $table->string('contentType');
            $table->string('jlptLevel')->nullable();
            $table->boolean('autoGenerateAudio');
            $table->string('status');
            $table->boolean('isSampleContent');
            $table->text('audioUrl')->nullable();
            $table->string('audioSpeed')->nullable();
            $table->text('audioUrl_0_7')->nullable();
            $table->text('audioUrl_0_85')->nullable();
            $table->text('audioUrl_1_0')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('Episode')->insert([
            self::row($ids['dialogueEpisode'], $ids['user'], 'Dialogue', $created),
            self::row($ids['scriptEpisode'], $ids['user'], 'script', '2026-07-20 11:00:00.456'),
        ]);
    }

    public static function row(string $id, string $userId, string $contentType, string $updatedAt): array
    {
        return [
            'id' => $id, 'userId' => $userId, 'title' => ucfirst($contentType).' episode',
            'sourceText' => 'Source text', 'targetLanguage' => 'ja', 'nativeLanguage' => 'en',
            'contentType' => $contentType, 'jlptLevel' => 'N5', 'autoGenerateAudio' => true,
            'status' => 'ready', 'isSampleContent' => false, 'audioUrl' => null,
            'audioSpeed' => 'medium', 'audioUrl_0_7' => null, 'audioUrl_0_85' => null,
            'audioUrl_1_0' => null, 'createdAt' => '2026-07-20 10:00:00.123', 'updatedAt' => $updatedAt,
        ];
    }
}
