<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabSentenceSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('Sentence', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dialogueId');
            $table->string('speakerId');
            $table->integer('order');
            $table->text('text');
            $table->text('translation');
            $table->json('metadata')->nullable();
            $table->text('audioUrl')->nullable();
            $table->integer('startTime')->nullable();
            $table->integer('endTime')->nullable();
            foreach (['startTime_0_7', 'endTime_0_7', 'startTime_0_85', 'endTime_0_85', 'startTime_1_0', 'endTime_1_0'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->json('variations')->nullable();
            $table->boolean('selected');
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('Sentence')->insert([
            'id' => $ids['sentence'], 'dialogueId' => $ids['dialogue'], 'speakerId' => $ids['speaker'],
            'order' => 1, 'text' => '猫です。', 'translation' => 'It is a cat.',
            'metadata' => json_encode(['japanese' => ['kanji' => '猫です。']]),
            'audioUrl' => null, 'startTime' => 0, 'endTime' => 800,
            'startTime_0_7' => null, 'endTime_0_7' => null,
            'startTime_0_85' => null, 'endTime_0_85' => null,
            'startTime_1_0' => null, 'endTime_1_0' => null,
            'variations' => json_encode(['猫だ。']), 'selected' => true,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
    }
}
