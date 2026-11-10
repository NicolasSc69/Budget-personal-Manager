<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Account colors: automatic unique color on creation, uniqueness check on the edit form,
 * "Randomize colors" on the account list and the colors sent to the dashboard chart.
 */
final class AccountColorTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testAccountsPersistedWithoutColorGetDistinctColors(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();
        $this->entityManager->flush();

        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) $first->getColor());
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', (string) $second->getColor());
        self::assertNotSame($first->getColor(), $second->getColor());
    }

    public function testEditingAnAccountWithAColorAlreadyUsedIsRejected(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/account/%d/edit', $second->getId()));
        $form = $crawler->filter('form[name="account_form"]')->form();
        // The color picker submits lower-case values: the uniqueness check must still catch it.
        $form['account_form[color]'] = strtolower((string) $first->getColor());
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This color is already used by another account.');
    }

    public function testEditingAnAccountWithAFreeColorIsSaved(): void
    {
        $account = $this->createAccount();
        $this->entityManager->flush();

        $usedColors = static::getContainer()->get(AccountRepository::class)->findUsedColors();
        $freeColor = '#123456';
        self::assertNotContains($freeColor, $usedColors);

        $crawler = $this->client->request('GET', \sprintf('/account/%d/edit', $account->getId()));
        $form = $crawler->filter('form[name="account_form"]')->form();
        $form['account_form[color]'] = $freeColor;
        $this->client->submit($form);

        self::assertResponseRedirects('/account');
        $this->entityManager->clear();
        self::assertSame(strtoupper($freeColor), $this->entityManager->find(Account::class, $account->getId())?->getColor());
    }

    public function testRandomizeColorsGivesEveryAccountADistinctColor(): void
    {
        $this->createAccount();
        $this->createAccount();
        $this->createAccount();
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form[action="/account/randomize-colors"]')->form());

        self::assertResponseRedirects('/account');

        $this->entityManager->clear();
        $colors = static::getContainer()->get(AccountRepository::class)->findUsedColors();
        self::assertCount(\count(array_unique($colors)), $colors);
        foreach ($colors as $color) {
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $color);
        }
    }

    public function testDashboardChartUsesTheAccountColor(): void
    {
        $account = $this->createAccount();
        $this->entityManager->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            json_encode($account->getColor(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    private function createAccount(): Account
    {
        $suffix = uniqid('', true);

        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $this->entityManager->persist($account);

        return $account;
    }
}
