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
            $this->assertSame('N5', $row['jlpt_level']);
        }

        fclose($handle);

        $this->assertCount($expectedRows, $ids);
        $this->assertCount($expectedRows, array_unique($ids));
    }

    /** @return array<string, array{string, string, int}> */
    public static function catalogProvider(): array
    {
        return [
            'vocabulary' => ['n5-vocabulary.csv', 'cf33a3040e31192658ae4c4b6a43485711837cc0be79004637956fac460e3ee6', 684],
            'grammar' => ['n5-grammar.csv', '9c0cb4e336928a014a8487df009fc796dd9ab5e294fc99b44da9a0ceb8b44a4c', 77],
        ];
    }
}
