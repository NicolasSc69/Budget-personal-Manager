<?php

namespace App\Tests\Controller;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One smoke test per top-level page: loads it and checks it renders
 * successfully. Catches the class of bug this app has hit repeatedly —
 * a page throwing a 500 because of a missing guard, a broken template
 * variable, or a route/service wiring mistake — without needing to model
 * every page's business logic.
 */
final class PageSmokeTest extends WebTestCase
{
    #[DataProvider('providePages')]
    public function testPageLoadsSuccessfully(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful(\sprintf('Expected "%s" to return a successful response.', $path));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function providePages(): iterable
    {
        yield 'dashboard' => ['/'];
        yield 'accounts' => ['/account'];
        yield 'account types' => ['/account-type'];
        yield 'tags' => ['/category'];
        yield 'import tags' => ['/category/import'];
        yield 'people' => ['/person'];
        yield 'transactions' => ['/transaction'];
        yield 'recurring transactions' => ['/transaction/recurring'];
        yield 'admin' => ['/admin'];
        yield 'translate interface' => ['/admin/translations'];
    }

    public function testDashboardShowsTheAppTitle(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
    }

    public function testUnknownAccountReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTransactionPdfExportReturnsAPdfFile(): void
    {
        $client = static::createClient();
        $client->request('GET', '/transaction/export/pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment; filename="transactions-', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    public function testTransactionPdfExportAppliesFilters(): void
    {
        $client = static::createClient();
        $client->request('GET', '/transaction/export/pdf', ['search' => 'this title should not match anything']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testAdminSettingsSavesTheRecurringCheckDay(): void
    {
        $client = static::createClient();
        $settings = static::getContainer()->get(AppSettingRepository::class)->getSettings();
        $original = [$settings->getItemsPerPage(), $settings->getTheme(), $settings->getRecurringCheckDay()];

        try {
            $crawler = $client->request('GET', '/admin');
            self::assertSelectorExists('input[name="recurring_check_day"]');

            $client->submit($crawler->filter('form[action$="/admin/settings"]')->form(['recurring_check_day' => '15']));
            self::assertResponseRedirects('/admin');
            self::assertSame(15, $this->reloadSettings()->getRecurringCheckDay());

            $crawler = $client->request('GET', '/admin');
            self::assertSelectorExists('input[name="recurring_check_day"][value="15"]');

            $client->submit($crawler->filter('form[action$="/admin/settings"]')->form(['recurring_check_day' => '32']));
            self::assertResponseRedirects('/admin');
            self::assertSame(15, $this->reloadSettings()->getRecurringCheckDay(), 'An out-of-range check day must be rejected.');
        } finally {
            $settings = $this->reloadSettings();
            [$itemsPerPage, $theme, $checkDay] = $original;
            $settings->setItemsPerPage($itemsPerPage)->setTheme($theme)->setRecurringCheckDay($checkDay);
            static::getContainer()->get('doctrine')->getManager()->flush();
        }
    }

    private function reloadSettings(): AppSetting
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $entityManager->clear();

        return static::getContainer()->get(AppSettingRepository::class)->getSettings();
    }
}
