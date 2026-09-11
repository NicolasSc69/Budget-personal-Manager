<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->transactionRepository = $this->entityManager->getRepository(Transaction::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testFindFilteredForExportSortsByTagThenTitleThenDate(): void
    {
        $suffix = uniqid();

        $account = $this->createAccount($suffix);
        $tagA = $this->createCategory('AAA Tag '.$suffix);
        $tagB = $this->createCategory('BBB Tag '.$suffix);

        // Same tag (AAA), same title ("Alp"): the later date must sort after the earlier one.
        $alpLate = $this->createTransaction($account, $tagA, 'Alp', '2024-06-01');
        $alpEarly = $this->createTransaction($account, $tagA, 'Alp', '2024-01-01');
        // Same tag (AAA), different title: "Zed" sorts after "Alp" regardless of date.
        $zed = $this->createTransaction($account, $tagA, 'Zed', '2024-01-01');
        // Different tag (BBB): sorts after every AAA-tagged transaction.
        $other = $this->createTransaction($account, $tagB, 'Same', '2024-01-01');

        $this->entityManager->flush();

        $results = $this->transactionRepository->findFilteredForExport(account: $account);

        self::assertSame(
            [$alpEarly->getId(), $alpLate->getId(), $zed->getId(), $other->getId()],
            array_map(static fn (Transaction $t) => $t->getId(), $results),
        );
    }

    private function createAccount(string $suffix): Account
    {
        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $this->entityManager->persist($account);

        return $account;
    }

    private function createCategory(string $name): Category
    {
        $category = new Category();
        $category->setName($name);
        $this->entityManager->persist($category);

        return $category;
    }

    private function createTransaction(Account $account, Category $category, string $title, string $date): Transaction
    {
        $transaction = new Transaction();
        $transaction->setAccount($account);
        $transaction->setCategory($category);
        $transaction->setTitle($title);
        $transaction->setAmount('-10.00');
        $transaction->setDate(new \DateTimeImmutable($date));
        $this->entityManager->persist($transaction);

        return $transaction;
    }
}
