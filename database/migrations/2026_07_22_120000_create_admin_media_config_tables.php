<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONVOLAB_TIMESTAMP_PRECISION = 3;

    public function up(): void
    {
        Schema::create('admin_speaker_avatars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('filename')->unique();
            $table->text('cropped_url');
            $table->text('original_url');
            $table->string('language', 16);
            $table->string('gender', 16);
            $table->string('tone', 16);
            $table->string('source_system', 32)->default('convolab');
            $table->timestampTz('created_at', self::CONVOLAB_TIMESTAMP_PRECISION);
            $table->timestampTz('updated_at', self::CONVOLAB_TIMESTAMP_PRECISION);
            $table->index(
                ['language', 'gender', 'tone', 'id'],
                'admin_speaker_avatars_order_idx',
            );
        });

        Schema::create('admin_pronunciation_dictionaries', function (Blueprint $table): void {
            $table->string('locale', 16)->primary();
            $table->json('keep_kanji');
            $table->json('force_kana');
            $table->json('verb_kana');
            $table->timestampTz('updated_at', self::CONVOLAB_TIMESTAMP_PRECISION)->nullable();
        });

        DB::table('admin_pronunciation_dictionaries')->insert([
            'locale' => 'ja',
            'keep_kanji' => json_encode(
                ['橋', '箸', '端', '今', '居間', '牡蠣', '垣', '柿', '酒', '鮭', '二本', '日本'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            'force_kana' => json_encode([
                '北海道' => 'ほっかいどう',
                '札幌' => 'さっぽろ',
                '函館' => 'はこだて',
                '小樽' => 'おたる',
                '釧路' => 'くしろ',
                '稚内' => 'わっかない',
                '帯広' => 'おびひろ',
                '旭川' => 'あさひかわ',
                '大通公園' => 'おおどおりこうえん',
                '新宿' => 'しんじゅく',
                '渋谷' => 'しぶや',
                '浅草' => 'あさくさ',
                '上野' => 'うえの',
                '梅田' => 'うめだ',
                '難波' => 'なんば',
                '心斎橋' => 'しんさいばし',
                '祇園' => 'ぎおん',
                '嵐山' => 'あらしやま',
                '清水寺' => 'きよみずでら',
                '季節' => 'きせつ',
                '物価' => 'ぶっか',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'verb_kana' => json_encode(
                ['話す' => 'はなす'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            'updated_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_pronunciation_dictionaries');
        Schema::dropIfExists('admin_speaker_avatars');
    }
};
