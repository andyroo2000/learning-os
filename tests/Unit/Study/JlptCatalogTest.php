<?php

namespace Tests\Unit\Study;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JlptCatalogTest extends TestCase
{
    #[DataProvider('catalogProvider')]
    public function test_versioned_catalog_has_expected_checksum_count_and_unique_ids(
        string $filename,
        string $checksum,
        int $expectedRows,
        string $expectedLevel,
    ): void {
        $path = LEARNING_OS_PROJECT_ROOT.'/resources/jlpt/v1/'.$filename;

        $this->assertFileExists($path);
        $this->assertSame($checksum, hash_file('sha256', $path));

        $handle = fopen($path, 'rb');
        $this->assertIsResource($handle);
        $header = fgetcsv($handle, escape: '');
        $this->assertIsArray($header);
        $ids = [];

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $this->assertCount(count($header), $values);
            $row = array_combine($header, $values);
            $this->assertIsArray($row);
            $ids[] = $row['concept_id'];
            $this->assertSame($expectedLevel, $row['jlpt_level']);
        }

        fclose($handle);

        $this->assertCount($expectedRows, $ids);
        $this->assertCount($expectedRows, array_unique($ids));
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function catalogProvider(): array
    {
        return [
            'N5 vocabulary' => ['n5-vocabulary.csv', 'cf33a3040e31192658ae4c4b6a43485711837cc0be79004637956fac460e3ee6', 684, 'N5'],
            'N5 grammar' => ['n5-grammar.csv', '9c0cb4e336928a014a8487df009fc796dd9ab5e294fc99b44da9a0ceb8b44a4c', 77, 'N5'],
            'N4 vocabulary' => ['n4-vocabulary.csv', '3ef6b09a483ccc61fa3f2ed96552e7574b2cbba67dbd442226599947b5f64c73', 640, 'N4'],
            'N4 grammar' => ['n4-grammar.csv', 'b736cde06c9d8d744d405340e904764c1d86cd385c98ca4a3e3e3a099ca540dd', 89, 'N4'],
        ];
    }
}
