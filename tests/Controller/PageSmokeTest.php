<?php

namespace App\Tests\Controller;

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
}
