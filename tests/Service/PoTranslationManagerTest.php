<?php

namespace App\Tests\Service;

use App\Service\PoTranslationManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PoTranslationManagerTest extends TestCase
{
    private string $translationsDir;
    private string $configFile;
    private PoTranslationManager $manager;

    protected function setUp(): void
    {
        $this->translationsDir = sys_get_temp_dir().'/compta-po-test-'.uniqid();
        $this->configFile = $this->translationsDir.'/translation.yaml';

        (new Filesystem())->mkdir($this->translationsDir);
        file_put_contents($this->configFile, "framework:\n    enabled_locales: ['en']\n");
        file_put_contents(
            $this->translationsDir.'/messages.en.po',
            "msgid \"\"\nmsgstr \"\"\n\nmsgid \"Hello\"\nmsgstr \"Hello\"\n",
        );

        $this->manager = new PoTranslationManager($this->translationsDir, $this->configFile);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->translationsDir);
    }

    public function testAvailableLocalesAreDerivedFromDiskWithSourceFirst(): void
    {
        file_put_contents($this->translationsDir.'/messages.fr.po', "msgid \"\"\nmsgstr \"\"\n");

        self::assertSame(['en', 'fr'], $this->manager->getAvailableLocales());
    }

    public function testAddLocaleCreatesAnIdentityPoFileAndUpdatesConfig(): void
    {
        $this->manager->addLocale('es');

        self::assertContains('es', $this->manager->getAvailableLocales());
        self::assertSame(['Hello' => ''], $this->manager->getTranslations('es'));
        self::assertStringContainsString("'es'", file_get_contents($this->configFile));
    }

    public function testAddLocaleRejectsAnAlreadyAvailableLocale(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->addLocale('en');
    }

    public function testAddLocaleRejectsAnInvalidCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->addLocale('002');
    }

    public function testRemoveLocaleDeletesItsFileAndConfigEntry(): void
    {
        $this->manager->addLocale('es');

        $this->manager->removeLocale('es');

        self::assertNotContains('es', $this->manager->getAvailableLocales());
        self::assertFileDoesNotExist($this->translationsDir.'/messages.es.po');
        self::assertStringNotContainsString("'es'", file_get_contents($this->configFile));
    }

    public function testTheSourceLocaleCanNeverBeRemoved(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->removeLocale('en');
    }

    public function testSetTranslationsPersistsAndReloadsCorrectly(): void
    {
        $this->manager->addLocale('es');

        $this->manager->setTranslations('es', ['Hello' => 'Hola']);

        self::assertSame(['Hello' => 'Hola'], $this->manager->getTranslations('es'));
    }

    public function testGetAddableLocalesExcludesAlreadyAvailableLocalesAndGroupsByContinent(): void
    {
        $groups = $this->manager->getAddableLocales('en');

        self::assertNotEmpty($groups);

        $allCodes = [];
        foreach ($groups as $group) {
            self::assertArrayHasKey('label', $group);
            self::assertArrayHasKey('locales', $group);
            $allCodes = [...$allCodes, ...array_keys($group['locales'])];
        }

        self::assertNotContains('en', $allCodes, 'The source locale is already available and must not be offered again.');
        self::assertContains('fr', $allCodes);
        self::assertContains('es', $allCodes);
    }
}
