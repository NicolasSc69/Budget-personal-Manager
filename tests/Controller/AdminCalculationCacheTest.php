<?php

namespace App\Tests\Controller;

use App\Repository\AppSettingRepository;
use App\Repository\TransactionRepository;
use App\Service\BudgetForecastService;
use App\Service\CalculationCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The admin page details the calculation cache of each entity, each one with its own clear button.
 */
final class AdminCalculationCacheTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->beginTransaction();

        // The assertions check English labels: don't depend on the language configured in the
        // test database (rolled back in tearDown()).
        static::getContainer()->get(AppSettingRepository::class)->getSettings()->setLocale('en');
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->rollBack();

        parent::tearDown();
    }

    public function testEachEntityCacheIsDetailedWithItsOwnClearButton(): void
    {
        $this->primeCaches();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSame(['transaction', 'account', 'category', 'person'], $crawler->filter('#calculation-caches tbody tr')->each(static fn (Crawler $row) => $row->attr('data-cache')));

        $transactionRow = $crawler->filter('#calculation-caches tr[data-cache="transaction"]');
        // The calculations are listed in a dropdown, with their number of entries.
        self::assertStringContainsString('3 calculation(s)', $transactionRow->filter('[data-bs-toggle="dropdown"]')->text());
        $items = $transactionRow->filter('.dropdown-menu li')->each(static fn (Crawler $li) => preg_replace('/\s+/', ' ', trim($li->text())));
        self::assertSame(['BudgetForecastService::getCategoryBreakdown 1', 'TransactionRepository::sumAll 1', 'TransactionRepository::sumUntil 1'], $items);
        self::assertSame('3', $transactionRow->filter('td')->eq(2)->text());
        self::assertCount(1, $transactionRow->filter('form[action$="/admin/calculation-cache/transaction/clear"] button:not([disabled])'));

        // Nothing depends on people yet: empty row, button disabled.
        $personRow = $crawler->filter('#calculation-caches tr[data-cache="person"]');
        self::assertSame('0', $personRow->filter('td')->eq(2)->text());
        self::assertCount(0, $personRow->filter('.dropdown'));
        self::assertCount(1, $personRow->filter('button[disabled]'));
    }

    /**
     * The test client resets the (in-memory) cache between requests, so what the clear actually
     * drops is covered by CalculationCacheTest; this checks the button reaches it.
     */
    public function testClearButtonClearsTheEntityCache(): void
    {
        $crawler = $this->client->request('GET', '/admin');

        $this->client->submit($crawler->filter('form[action$="/admin/calculation-cache/category/clear"]')->form());

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast.text-bg-success', 'Tags');
    }

    public function testClearingRequiresAValidToken(): void
    {
        $this->client->request('POST', '/admin/calculation-cache/category/clear', ['_token' => 'invalid']);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorExists('.toast.text-bg-danger');
        self::assertSelectorNotExists('.toast.text-bg-success');
    }

    public function testUnknownCacheIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $token = $crawler->filter('form[action$="/admin/calculation-cache/transaction/clear"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/calculation-cache/unknown/clear', ['_token' => $token]);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorExists('.toast.text-bg-danger');
    }

    public function testEachEnvironmentCacheHasItsOwnClearButton(): void
    {
        $crawler = $this->client->request('GET', '/admin');

        // Not submitted: it would delete this very test run's cache directory.
        self::assertCount(1, $crawler->filter('form[action$="/admin/clear-cache"] input[name="env"][value="test"]'));
    }

    /**
     * Two calculations (sumAll, sumUntil) in the transaction + account caches, one (getCategoryBreakdown) in the transaction + tag caches.
     */
    private function primeCaches(): void
    {
        $container = static::getContainer();
        $container->get(CalculationCache::class)->invalidateAll();
        $container->get(TransactionRepository::class)->sumAll();
        $container->get(TransactionRepository::class)->sumUntil(new \DateTimeImmutable('today'));
        $container->get(BudgetForecastService::class)->getCategoryBreakdown(new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('today'));
    }
}
