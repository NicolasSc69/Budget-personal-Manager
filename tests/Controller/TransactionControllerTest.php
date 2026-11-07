<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Person;
use App\Entity\Transaction;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Transaction list filters and the create / edit / duplicate / delete forms, run against the
 * test database inside a transaction rolled back after each test.
 */
final class TransactionControllerTest extends WebTestCase
{
    private const XHR = ['HTTP_X-Requested-With' => 'XMLHttpRequest'];

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $suffix;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep the same kernel (and database connection) across requests, so the rollback below
        // also undoes what the requests wrote.
        $this->client->disableReboot();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->suffix = uniqid();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testPersonFilterIsListedAfterTheAccountFilter(): void
    {
        $person = $this->createPerson('Person '.$this->suffix);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction');

        self::assertResponseIsSuccessful();
        $selects = $crawler->filter('#transaction-filter-form select')->each(static fn ($node) => $node->attr('name'));
        self::assertSame(['account', 'person', 'category'], $selects);
        self::assertSelectorExists(\sprintf('#filter-person option[value="%d"]', $person->getId()));
    }

    public function testPersonFilterListsOnlyTheTransactionsOfThePersonsAccounts(): void
    {
        $alice = $this->createPerson('Alice '.$this->suffix);
        $bob = $this->createPerson('Bob '.$this->suffix);
        $category = $this->createCategory();
        $this->createTransaction($this->createAccount($alice), $category, 'AliceChecking '.$this->suffix);
        $this->createTransaction($this->createAccount($alice), $category, 'AliceSavings '.$this->suffix);
        $this->createTransaction($this->createAccount($bob), $category, 'BobOnly '.$this->suffix);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction', ['person' => $alice->getId(), 'search' => $this->suffix]);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('AliceChecking '.$this->suffix, $content);
        self::assertStringContainsString('AliceSavings '.$this->suffix, $content);
        self::assertStringNotContainsString('BobOnly '.$this->suffix, $content);

        self::assertSelectorExists(\sprintf('#filter-person option[value="%d"][selected]', $alice->getId()));
        // Sorting keeps the person filter.
        $sortLink = $crawler->filter('thead a')->first()->attr('href');
        self::assertStringContainsString('person='.$alice->getId(), (string) $sortLink);
        // So does the PDF export.
        $exportLink = $crawler->filter('a[href*="/transaction/export/pdf"]')->attr('href');
        self::assertStringContainsString('person='.$alice->getId(), (string) $exportLink);
    }

    public function testPdfExportAcceptsThePersonFilter(): void
    {
        $person = $this->createPerson('Person '.$this->suffix);
        $this->createTransaction($this->createAccount($person), $this->createCategory(), 'Exported '.$this->suffix);
        $this->entityManager->flush();

        $this->client->request('GET', '/transaction/export/pdf', ['person' => $person->getId()]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testUnknownPersonIsIgnored(): void
    {
        $this->client->request('GET', '/transaction', ['person' => 999999]);

        self::assertResponseIsSuccessful();
    }

    public function testCreateEditAndDeleteATransactionThroughTheModalForm(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->entityManager->flush();
        $title = 'Created '.$this->suffix;

        // Create.
        $crawler = $this->client->request('GET', '/transaction/new', ['account' => $account->getId()], [], self::XHR);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="transaction"]')->form();
        $form['transaction[title]'] = $title;
        $form['transaction[amount]'] = '12.34';
        $form['transaction[date]'] = '2024-05-10';
        $form['transaction[type]']->select('expense');
        $form['transaction[category]']->select((string) $category->getId());
        $this->client->submit($form, [], self::XHR);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($json['success']);
        self::assertSame('/compte/'.$account->getId(), $json['redirect']);

        $transaction = $this->findByTitle($title);
        self::assertNotNull($transaction);
        self::assertEqualsWithDelta(-12.34, (float) $transaction->getAmount(), 0.001);
        $id = $transaction->getId();

        // Edit: switching to an income keeps the amount positive.
        $crawler = $this->client->request('GET', '/transaction/'.$id.'/edit', [], [], self::XHR);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="transaction"]')->form();
        self::assertSame('12.34', $form['transaction[amount]']->getValue());
        $form['transaction[amount]'] = '50';
        $form['transaction[type]']->select('income');
        $this->client->submit($form, [], self::XHR);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);
        self::assertEqualsWithDelta(50.0, (float) $this->findByTitle($title)->getAmount(), 0.001);

        // Delete.
        $crawler = $this->client->request('GET', '/transaction/'.$id.'/edit', [], [], self::XHR);
        $this->client->submit($crawler->filter(\sprintf('form[action$="/transaction/%d"]', $id))->form());
        self::assertResponseRedirects();
        self::assertNull($this->findByTitle($title));
    }

    public function testDeferredDebitDateIsComputedKeptOrClearedOnSave(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->entityManager->flush();

        // Ticked with no debit date: computed from the transaction date (21st → 5th of the month after next).
        $form = $this->newTransactionForm($account, $category, 'Deferred '.$this->suffix, '2026-01-21');
        $form['transaction[isDeferredDebit]']->tick();
        $form['transaction[debitDate]'] = '';
        $this->client->submit($form, [], self::XHR);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);
        $transaction = $this->findByTitle('Deferred '.$this->suffix);
        self::assertTrue($transaction->isDeferredDebit());
        self::assertSame('2026-03-05', $transaction->getDebitDate()->format('Y-m-d'));

        // A debit date typed by hand is kept as is.
        $form = $this->newTransactionForm($account, $category, 'Custom '.$this->suffix, '2026-01-10');
        $form['transaction[isDeferredDebit]']->tick();
        $form['transaction[debitDate]'] = '2026-02-12';
        $this->client->submit($form, [], self::XHR);
        self::assertSame('2026-02-12', $this->findByTitle('Custom '.$this->suffix)->getDebitDate()->format('Y-m-d'));

        // Unticking the checkbox clears the debit date.
        $crawler = $this->client->request('GET', '/transaction/'.$transaction->getId().'/edit', [], [], self::XHR);
        $form = $crawler->filter('form[name="transaction"]')->form();
        self::assertSame('2026-03-05', $form['transaction[debitDate]']->getValue());
        $form['transaction[isDeferredDebit]']->untick();
        $this->client->submit($form, [], self::XHR);
        $transaction = $this->findByTitle('Deferred '.$this->suffix);
        self::assertFalse($transaction->isDeferredDebit());
        self::assertNull($transaction->getDebitDate());
        self::assertSame('2026-01-21', $transaction->getEffectiveDate()->format('Y-m-d'));
    }

    public function testDeferredDebitFilterListsOnlyDeferredPayments(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createTransaction($account, $category, 'Card '.$this->suffix)
            ->setIsDeferredDebit(true)->setDebitDate(new \DateTimeImmutable('2024-06-05'));
        $this->createTransaction($account, $category, 'Cash '.$this->suffix);
        $this->entityManager->flush();

        $this->client->request('GET', '/transaction', ['search' => $this->suffix, 'deferred_debit' => '1']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Card '.$this->suffix, $content);
        self::assertStringNotContainsString('Cash '.$this->suffix, $content);
        self::assertSelectorExists('#filter-deferred-debit[checked]');
    }

    public function testInvalidSubmissionRedisplaysTheFormWithoutSaving(): void
    {
        $account = $this->createAccount();
        $this->entityManager->flush();
        $title = 'Invalid '.$this->suffix;

        $crawler = $this->client->request('GET', '/transaction/new', ['account' => $account->getId()], [], self::XHR);
        $form = $crawler->filter('form[name="transaction"]')->form();
        $form['transaction[title]'] = $title;
        $form['transaction[amount]'] = '-5';
        $form['transaction[date]'] = '2024-05-10';
        $this->client->submit($form, [], self::XHR);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[name="transaction"]');
        self::assertNull($this->findByTitle($title));
    }

    public function testDuplicateFormIsPrefilledFromTheSourceTransaction(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $source = $this->createTransaction($account, $category, 'Source '.$this->suffix, '-42.50');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction/new', ['duplicate' => $source->getId()], [], self::XHR);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="transaction"]')->form();
        self::assertSame('Source '.$this->suffix, $form['transaction[title]']->getValue());
        self::assertSame('42.50', $form['transaction[amount]']->getValue());
        self::assertSame('expense', $form['transaction[type]']->getValue());
        self::assertSame((string) $account->getId(), $form['transaction[account]']->getValue());
        self::assertSame((string) $category->getId(), $form['transaction[category]']->getValue());

        // Saving it creates a second, independent transaction.
        $this->client->submit($form, [], self::XHR);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);
        self::assertCount(2, $this->entityManager->getRepository(Transaction::class)->findBy(['title' => 'Source '.$this->suffix]));
    }

    public function testBulkDeleteRemovesTheSelectedTransactions(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $first = $this->createTransaction($account, $category, 'Bulk1 '.$this->suffix);
        $second = $this->createTransaction($account, $category, 'Bulk2 '.$this->suffix);
        $kept = $this->createTransaction($account, $category, 'Kept '.$this->suffix);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction', ['search' => $this->suffix]);
        $token = $crawler->filter('input[name="_token"]')->reduce(
            static fn ($node) => str_contains((string) $node->closest('form')?->attr('action'), '/transaction/bulk-delete'),
        )->attr('value');

        $this->client->request('POST', '/transaction/bulk-delete', [
            '_token' => $token,
            'ids' => [$first->getId(), $second->getId()],
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->findByTitle('Bulk1 '.$this->suffix));
        self::assertNull($this->findByTitle('Bulk2 '.$this->suffix));
        self::assertNotNull($this->findByTitle('Kept '.$this->suffix));
        self::assertNotNull($kept->getId());
    }

    public function testPagesIncludeTheLoadingScreenWithAProgressBar(): void
    {
        $this->client->request('GET', '/transaction');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#account-nav-overlay [role="progressbar"]');
        self::assertSelectorExists('#account-nav-overlay-bar');
        self::assertSelectorExists('#account-nav-overlay-percent');
        // File downloads don't leave the page: the loading screen must not be shown for them.
        self::assertSelectorExists('a[href*="/transaction/export/pdf"][data-no-page-loader]');
    }

    public function testLoadingScreenIsTranslated(): void
    {
        // The interface language comes from the settings (see LocaleSubscriber).
        $settings = static::getContainer()->get(AppSettingRepository::class)->getSettings();
        $settings->setLocale('fr');
        $this->entityManager->flush();

        $this->client->request('GET', '/transaction');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Chargement en cours', (string) $this->client->getResponse()->getContent());
    }

    public function testFilteredListIsSortedByAscendingDateByDefault(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        // Created in an order that differs from both the date order and the reverse id order.
        $this->createTransaction($account, $category, 'Middle '.$this->suffix, '-10.00', '2024-05-15');
        $this->createTransaction($account, $category, 'Latest '.$this->suffix, '-10.00', '2024-06-01');
        $this->createTransaction($account, $category, 'Earliest '.$this->suffix, '-10.00', '2024-04-01');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction', ['search' => $this->suffix]);

        self::assertResponseIsSuccessful();
        self::assertSame(['Earliest', 'Middle', 'Latest'], $this->listedTitles());
        // The date column header shows the active ascending sort: the next click sorts descending.
        $dateLink = (string) $crawler->filter('thead a[href*="sort=date"]')->attr('href');
        self::assertStringContainsString('direction=desc', $dateLink);
    }

    public function testAnExplicitSortWinsOverTheFilteredDefault(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createTransaction($account, $category, 'Middle '.$this->suffix, '-10.00', '2024-05-15');
        $this->createTransaction($account, $category, 'Latest '.$this->suffix, '-10.00', '2024-06-01');
        $this->createTransaction($account, $category, 'Earliest '.$this->suffix, '-10.00', '2024-04-01');
        $this->entityManager->flush();

        $this->client->request('GET', '/transaction', ['search' => $this->suffix, 'sort' => 'date', 'direction' => 'desc']);

        self::assertResponseIsSuccessful();
        self::assertSame(['Latest', 'Middle', 'Earliest'], $this->listedTitles());
    }

    public function testUnfilteredListKeepsTheNewestCreatedFirst(): void
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $this->createTransaction($account, $category, 'First '.$this->suffix, '-10.00', '2024-06-01');
        $this->entityManager->flush();
        $this->createTransaction($account, $category, 'Second '.$this->suffix, '-10.00', '2024-04-01');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction');

        self::assertResponseIsSuccessful();
        // Default sort is by id, descending: the last created transaction comes first.
        self::assertSame(['Second', 'First'], $this->listedTitles());
        self::assertCount(0, $crawler->filter('thead a[href*="sort=date"][href*="direction=desc"]'));
    }

    public function testNewTransactionFormDefaultsToToday(): void
    {
        $crawler = $this->client->request('GET', '/transaction/new', [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="transaction"]')->form();
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $form['transaction[date]']->getValue());
    }

    public function testDuplicateFormKeepsTheSourceDate(): void
    {
        $source = $this->createTransaction($this->createAccount(), $this->createCategory(), 'Source '.$this->suffix, '-10.00', '2024-03-12');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/transaction/new', ['duplicate' => $source->getId()], [], self::XHR);

        $form = $crawler->filter('form[name="transaction"]')->form();
        self::assertSame('2024-03-12', $form['transaction[date]']->getValue());
    }

    public function testEditModalShowsAllActionButtonsOnASingleRow(): void
    {
        $transaction = $this->createTransaction($this->createAccount(), $this->createCategory(), 'Row '.$this->suffix);
        $this->entityManager->flush();
        $id = $transaction->getId();

        $crawler = $this->client->request('GET', '/transaction/'.$id.'/edit', [], [], self::XHR);

        self::assertResponseIsSuccessful();
        $formId = (string) $crawler->filter('form[name="transaction"]')->attr('id');
        self::assertSame('transaction-form-'.$id, $formId);

        $row = $crawler->filter(\sprintf('button[type="submit"][form="%s"]', $formId))->closest('div');
        self::assertNotNull($row);
        self::assertStringContainsString('d-flex', (string) $row->attr('class'));
        self::assertStringContainsString('flex-sm-nowrap', (string) $row->attr('class'));
        self::assertCount(1, $row->filter('[data-role="cancel"]'));
        self::assertCount(1, $row->filter(\sprintf('form[action$="/transaction/%d"] button', $id)));

        // The save button, outside the form element, still submits it.
        $form = $crawler->filter(\sprintf('button[form="%s"]', $formId))->form();
        $form['transaction[title]'] = 'Renamed '.$this->suffix;
        $this->client->submit($form, [], self::XHR);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);
        self::assertNotNull($this->findByTitle('Renamed '.$this->suffix));
    }

    /**
     * Titles of the listed transactions created by the current test, in table order, without the suffix.
     *
     * @return list<string>
     */
    private function listedTitles(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        preg_match_all('/(\w+) '.preg_quote($this->suffix, '/').'/', $content, $matches, \PREG_OFFSET_CAPTURE);

        $titles = [];
        foreach ($matches[1] as [$word]) {
            if (!\in_array($word, $titles, true)) {
                $titles[] = $word;
            }
        }

        return $titles;
    }

    private function newTransactionForm(Account $account, Category $category, string $title, string $date): \Symfony\Component\DomCrawler\Form
    {
        $crawler = $this->client->request('GET', '/transaction/new', ['account' => $account->getId()], [], self::XHR);
        $form = $crawler->filter('form[name="transaction"]')->form();
        $form['transaction[title]'] = $title;
        $form['transaction[amount]'] = '25';
        $form['transaction[date]'] = $date;
        $form['transaction[type]']->select('expense');
        $form['transaction[category]']->select((string) $category->getId());

        return $form;
    }

    private function findByTitle(string $title): ?Transaction
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Transaction::class)->findOneBy(['title' => $title]);
    }

    private function createPerson(string $name): Person
    {
        $person = new Person();
        $person->setName($name);
        $this->entityManager->persist($person);

        return $person;
    }

    private function createAccount(?Person $person = null): Account
    {
        $suffix = uniqid('', true);

        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $account->setPerson($person);
        $this->entityManager->persist($account);

        return $account;
    }

    private function createCategory(): Category
    {
        $category = new Category();
        $category->setName('Test tag '.uniqid('', true));
        $this->entityManager->persist($category);

        return $category;
    }

    private function createTransaction(Account $account, Category $category, string $title, string $amount = '-10.00', string $date = '2024-05-01'): Transaction
    {
        $transaction = new Transaction();
        $transaction->setAccount($account);
        $transaction->setCategory($category);
        $transaction->setTitle($title);
        $transaction->setAmount($amount);
        $transaction->setDate(new \DateTimeImmutable($date));
        $this->entityManager->persist($transaction);

        return $transaction;
    }
}
