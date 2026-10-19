<?php

namespace Tests\Unit\Boq;

use PHPUnit\Framework\TestCase;

/**
 * Keeps database/seeders/data/boq/catalog_descriptions.php in lockstep with catalog.php: every
 * catalog item has an English and an Arabic description, and no description is orphaned by a
 * renamed or removed catalog key.
 */
class CatalogDescriptionsTest extends TestCase
{
    private const DATA_DIR = __DIR__.'/../../../database/seeders/data/boq';

    /** @return array<int, string> */
    private function catalogItemKeys(): array
    {
        $keys = [];

        foreach (require self::DATA_DIR.'/catalog.php' as $topLevel) {
            foreach ($topLevel['subcategories'] as $subcategory) {
                array_push($keys, ...array_keys($subcategory['items']));
            }
        }

        return $keys;
    }

    public function test_every_catalog_item_has_a_description_and_none_are_orphaned(): void
    {
        $catalogKeys = $this->catalogItemKeys();
        $descriptionKeys = array_keys(require self::DATA_DIR.'/catalog_descriptions.php');

        $this->assertSame([], array_values(array_diff($catalogKeys, $descriptionKeys)), 'Catalog items missing a description');
        $this->assertSame([], array_values(array_diff($descriptionKeys, $catalogKeys)), 'Descriptions for unknown catalog items');
    }

    public function test_every_description_has_non_empty_english_and_arabic_text(): void
    {
        foreach (require self::DATA_DIR.'/catalog_descriptions.php' as $key => [$english, $arabic]) {
            $this->assertNotSame('', trim($english), "{$key}: empty English description");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arabic, "{$key}: Arabic description has no Arabic text");
        }
    }
}
