<?php

namespace App\Tests\Controller;

use App\Repository\AppSettingRepository;
use App\Service\DatabaseExportTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The navbar invites to download the database once an entity was added, modified or deleted,
 * but not while merely browsing.
 */
final class DatabaseModifiedIndicatorTest extends WebTestCase
{
    private KernelBrowser $client;
    private DatabaseExportTracker $tracker;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->beginTransaction();

        static::getContainer()->get(AppSettingRepository::class)->getSettings()->setLocale('en');
        $entityManager->flush();

        $this->tracker = static::getContainer()->get(DatabaseExportTracker::class);
        $this->tracker->markExported();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->rollBack();
        $this->tracker->markExported();

        parent::tearDown();
    }

    public function testBrowsingDoesNotShowTheIndicator(): void
    {
        foreach (['/', '/transaction', '/account', '/category', '/admin'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }

        self::assertSelectorNotExists('#db-download-link.is-modified');
        self::assertFalse($this->tracker->hasChangesSinceExport());
    }

    public function testCreatingAnEntityShowsTheIndicatorUntilTheDatabaseIsDownloaded(): void
    {
        $crawler = $this->client->request('GET', '/person/new');
        $this->client->submit($crawler->filter('form[name="person"]')->form(['person[name]' => 'Indicator test']));

        $this->client->request('GET', '/');
        self::assertSelectorExists('#db-download-link.is-modified');

        $this->client->request('GET', '/admin/export');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('#db-download-link.is-modified');
    }
}
