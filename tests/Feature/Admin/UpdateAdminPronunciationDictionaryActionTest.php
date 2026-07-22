<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\UpdateAdminPronunciationDictionaryAction;
use App\Domain\Admin\Data\PronunciationDictionaryData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminPronunciationDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAdminPronunciationDictionaryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_action_updates_dictionary_and_preserves_an_omitted_verb_map(): void
    {
        $result = app(UpdateAdminPronunciationDictionaryAction::class)->handle(
            PronunciationDictionaryData::fromArray([
                'keepKanji' => [' 日本 ', '日本', '「橋」'],
                'forceKana' => [' 北 海 道 ' => ' ほっかいどう '],
            ]),
        );

        $this->assertSame(['日本', '橋'], $result->keep_kanji);
        $this->assertSame(['北海道' => 'ほっかいどう'], $result->force_kana);
        $this->assertSame(['話す' => 'はなす'], $result->verb_kana);
        $this->assertNotNull($result->updated_at);

        $stored = AdminPronunciationDictionary::query()->findOrFail('ja');
        $this->assertSame($result->keep_kanji, $stored->keep_kanji);
        $this->assertSame($result->force_kana, $stored->force_kana);
        $this->assertSame($result->verb_kana, $stored->verb_kana);
    }

    public function test_direct_action_replaces_an_explicit_verb_map(): void
    {
        $result = app(UpdateAdminPronunciationDictionaryAction::class)->handle(
            PronunciationDictionaryData::fromArray([
                'keepKanji' => [],
                'forceKana' => [],
                'verbKana' => [' 行く ' => ' いく '],
            ]),
        );

        $this->assertSame(['行く' => 'いく'], $result->verb_kana);
    }

    public function test_direct_data_contract_accepts_exact_entry_and_length_boundaries(): void
    {
        $keepKanji = array_fill(0, PronunciationDictionaryData::MAX_KEEP_KANJI_ENTRIES, '橋');
        $forceKana = [];
        for ($index = 0; $index < PronunciationDictionaryData::MAX_KANA_MAP_ENTRIES; $index++) {
            $forceKana['語'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)] = str_repeat('あ', 64);
        }

        $data = PronunciationDictionaryData::fromArray([
            'keepKanji' => $keepKanji,
            'forceKana' => $forceKana,
        ]);

        $this->assertSame(['橋'], $data->keepKanji);
        $this->assertCount(PronunciationDictionaryData::MAX_KANA_MAP_ENTRIES, $data->forceKana);
        $this->assertSame(str_repeat('あ', 64), $data->forceKana['語0000']);
        $this->assertNull($data->verbKana);
    }

    public function test_direct_data_contract_rejects_each_limit_overflow(): void
    {
        $tooManyMapEntries = [];
        for ($index = 0; $index <= PronunciationDictionaryData::MAX_KANA_MAP_ENTRIES; $index++) {
            $tooManyMapEntries['語'.$index] = 'かな';
        }

        $cases = [
            [
                ['keepKanji' => array_fill(0, 501, '橋'), 'forceKana' => []],
                'keepKanji must contain no more than 500 entries',
            ],
            [
                ['keepKanji' => [], 'forceKana' => $tooManyMapEntries],
                'forceKana must contain no more than 1000 entries',
            ],
            [
                ['keepKanji' => [str_repeat('橋', 65)], 'forceKana' => []],
                'keepKanji entries must be <= 64 characters',
            ],
        ];

        foreach ($cases as [$payload, $message]) {
            try {
                PronunciationDictionaryData::fromArray($payload);
                $this->fail('Expected direct dictionary validation to fail.');
            } catch (AdminMutationException $exception) {
                $this->assertSame($message, $exception->getMessage());
                $this->assertSame(400, $exception->status());
            }
        }

        $this->assertNull(AdminPronunciationDictionary::query()->findOrFail('ja')->updated_at);
    }
}
