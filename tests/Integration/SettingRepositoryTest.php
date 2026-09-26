<?php

declare(strict_types=1);

namespace Kochbuch\Tests\Integration;

use Kochbuch\Domain\Setting\SettingRepository;
use Kochbuch\Tests\TestCase;

/**
 * bin/setup-test-db.php deliberately clones table *structure* only (see its
 * own doc-comment), so the `setting` table's seed rows from
 * database/migrations/007_create_setting_table.sql don't exist in the test
 * database - every test here seeds its own rows via setMany() first, same
 * as TestCase::createUser() does for the `user` table.
 */
final class SettingRepositoryTest extends TestCase
{
    private SettingRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new SettingRepository($this->pdo);
    }

    public function testGetReturnsAPreviouslySetValue(): void
    {
        $this->settings->setMany(['app_name' => 'Kochbuch']);

        $this->assertSame('Kochbuch', $this->settings->get('app_name'));
    }

    public function testGetFallsBackToTheGivenDefaultWhenKeyIsMissing(): void
    {
        $this->assertSame('fallback', $this->settings->get('does_not_exist', 'fallback'));
        $this->assertNull($this->settings->get('does_not_exist'));
    }

    public function testGetBoolParsesStoredFlags(): void
    {
        $this->settings->setMany(['translate_tool_enabled' => '1']);
        $this->assertTrue($this->settings->getBool('translate_tool_enabled'));

        $this->settings->setMany(['translate_tool_enabled' => '0']);
        $this->assertFalse($this->settings->getBool('translate_tool_enabled'));
    }

    public function testGetIntListParsesACsvValue(): void
    {
        $this->settings->setMany(['recipe_page_sizes' => '10,20,100']);

        $this->assertSame([10, 20, 100], $this->settings->getIntList('recipe_page_sizes'));
    }

    public function testSetManyUpsertsAndOverwritesExistingValues(): void
    {
        $this->settings->setMany(['app_name' => 'Kochbuch', 'default_locale' => 'de']);
        $this->settings->setMany(['app_name' => 'Testbuch']);

        $this->assertSame('Testbuch', $this->settings->get('app_name'));
        // Untouched keys survive a setMany() call for a different subset.
        $this->assertSame('de', $this->settings->get('default_locale'));
    }
}
