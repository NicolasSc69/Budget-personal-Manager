<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use App\Repository\TransactionRepository;
use App\Service\RecurringTransactionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecurringTransactionGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;
    private RecurringTransactionGenerator $generator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->transactionRepository = $this->entityManager->getRepository(Transaction::class);
        $this->generator = self::getContainer()->get(RecurringTransactionGenerator::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->rollBack();
        $this->entityManager->close();

        parent::tearDown();
    }

    public function testDeletedLatestOccurrenceIsNotGeneratedAgain(): void
    {
        $template = $this->createMonthlyTemplate('-3 months');
        $this->generator->generateDueOccurrences();

        $occurrences = $this->findSeries($template);
        self::assertCount(4, $occurrences);

        $latest = end($occurrences);
        $this->generator->reassignRecurrenceBeforeRemoval([$latest]);
        $this->entityManager->remove($latest);
        $this->entityManager->flush();

        $this->generator->generateDueOccurrences();

        self::assertCount(3, $this->findSeries($template));
    }

    public function testDeletingTemplatePromotesOldestOccurrenceWithoutDuplicates(): void
    {
        $template = $this->createMonthlyTemplate('-3 months');
        $this->generator->generateDueOccurrences();
        $this->entityManager->refresh($template);

        $children = $template->getRecurrenceChildren()->toArray();
        self::assertCount(3, $children);

        $this->generator->reassignRecurrenceBeforeRemoval([$template]);
        $this->entityManager->remove($template);
        $this->entityManager->flush();

        $this->generator->generateDueOccurrences();

        $titles = $this->transactionRepository->findBy(['title' => $template->getTitle()]);
        self::assertCount(3, $titles);

        $newTemplates = array_filter($titles, static fn (Transaction $t) => !$t->isGeneratedFromRecurrence());
        self::assertCount(1, $newTemplates);
    }

    public function testDuplicateToNextMonthMovesSundayToMonday(): void
    {
        $nextMonthStart = new \DateTimeImmutable('first day of next month');
        $sunday = $nextMonthStart->modify('7' === $nextMonthStart->format('N') ? '+0 day' : 'next sunday');

        $template = $this->createMonthlyTemplate('-1 month');
        $template->setDate((new \DateTimeImmutable('first day of this month'))->setDate(
            (int) (new \DateTimeImmutable('today'))->format('Y'),
            (int) (new \DateTimeImmutable('today'))->format('n'),
            (int) $sunday->format('j'),
        ));
        $this->entityManager->flush();

        $pending = array_values(array_filter(
            $this->generator->findMonthlyTemplatesPendingNextMonth($template->getAccount()),
            static fn (array $entry) => $entry['template'] === $template,
        ));
        self::assertCount(1, $pending);
        self::assertSame($sunday->modify('+1 day')->format('Y-m-d'), $pending[0]['nextDate']->format('Y-m-d'));

        self::assertSame(1, $this->generator->duplicateMonthlyTemplatesToNextMonth([$template->getId()]));

        $series = $this->findSeries($template);
        self::assertSame('1', end($series)->getDate()->format('N'));
        self::assertSame([], array_filter(
            $this->generator->findMonthlyTemplatesPendingNextMonth($template->getAccount()),
            static fn (array $entry) => $entry['template'] === $template,
        ));
    }

    /**
     * @return list<Transaction>
     */
    private function findSeries(Transaction $template): array
    {
        return $this->transactionRepository->findBy(['title' => $template->getTitle()], ['date' => 'ASC']);
    }

    private function createMonthlyTemplate(string $startDate): Transaction
    {
        $suffix = uniqid();

        $type = new AccountType();
        $type->setName('Test type '.$suffix);
        $this->entityManager->persist($type);

        $account = new Account();
        $account->setLabel('Test account '.$suffix);
        $account->setType($type);
        $this->entityManager->persist($account);

        $category = new Category();
        $category->setName('Test tag '.$suffix);
        $this->entityManager->persist($category);

        $template = new Transaction();
        $template->setAccount($account);
        $template->setCategory($category);
        $template->setTitle('Recurring '.$suffix);
        $template->setAmount('-10.00');
        $template->setDate(new \DateTimeImmutable('today '.$startDate));
        $template->setRecurrence(Recurrence::MONTHLY);
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }
}
