<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use App\Repository\AppSettingRepository;
use App\Repository\TransactionRepository;
use App\Service\RecurringTransactionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecurringTransactionGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $transactionRepository;
    private RecurringTransactionGenerator $generator;
    private AppSettingRepository $appSettingRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->transactionRepository = $this->entityManager->getRepository(Transaction::class);
        $this->generator = self::getContainer()->get(RecurringTransactionGenerator::class);
        $this->appSettingRepository = self::getContainer()->get(AppSettingRepository::class);
        $this->entityManager->getConnection()->beginTransaction();
        // Default check day, so the pending list doesn't depend on what the test database holds.
        $this->setRecurringCheckDay(1);
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

    public function testPendingNextMonthIsListedFromTheCheckDay(): void
    {
        $template = $this->createMonthlyTemplate('-1 month');

        $this->setRecurringCheckDay((int) (new \DateTimeImmutable('today'))->format('j'));
        self::assertTrue($this->isPending($template));
    }

    public function testPendingNextMonthIsEmptyBeforeTheCheckDay(): void
    {
        $today = new \DateTimeImmutable('today');
        if ($today->format('j') === $today->format('t')) {
            self::markTestSkipped('On the last day of the month every check day is reached.');
        }

        $template = $this->createMonthlyTemplate('-1 month');

        $this->setRecurringCheckDay((int) $today->format('j') + 1);
        self::assertSame([], $this->generator->findMonthlyTemplatesPendingNextMonth($template->getAccount()));
        self::assertSame(0, $this->generator->duplicateMonthlyTemplatesToNextMonth([$template->getId()]));

        $this->setRecurringCheckDay(1);
        self::assertTrue($this->isPending($template));
    }

    public function testGeneratedOccurrencesOfADeferredTemplateGetTheirOwnDebitDate(): void
    {
        $template = $this->createMonthlyTemplate('-3 months');
        $template->setIsDeferredDebit(true);
        $template->setDebitDate(Transaction::computeDeferredDebitDate($template->getDate()));
        $this->entityManager->flush();

        $this->generator->generateDueOccurrences();

        $series = $this->findSeries($template);
        self::assertCount(4, $series);
        foreach ($series as $transaction) {
            self::assertTrue($transaction->isDeferredDebit());
            self::assertSame(
                Transaction::computeDeferredDebitDate($transaction->getDate())->format('Y-m-d'),
                $transaction->getDebitDate()?->format('Y-m-d'),
                'Debit date of the occurrence of '.$transaction->getDate()->format('Y-m-d'),
            );
        }
    }

    public function testGeneratedOccurrencesOfANonDeferredTemplateHaveNoDebitDate(): void
    {
        $template = $this->createMonthlyTemplate('-2 months');

        $this->generator->generateDueOccurrences();

        foreach ($this->findSeries($template) as $transaction) {
            self::assertFalse($transaction->isDeferredDebit());
            self::assertNull($transaction->getDebitDate());
        }
    }

    public function testShiftingASundayDeferredOccurrenceToMondayRecomputesItsDebitDate(): void
    {
        // Sunday 20 Sept 2026 → Monday 21: crosses the 20th, so the debit moves from 5 Oct to 5 Nov.
        $template = $this->createMonthlyTemplate('-1 month');
        $template->setDate(new \DateTimeImmutable('2026-09-20'));
        $template->setIsDeferredDebit(true);
        $template->setDebitDate(new \DateTimeImmutable('2026-10-05'));
        $this->entityManager->flush();

        self::assertSame(1, $this->generator->shiftSundayOccurrencesToMonday([$template->getId()]));

        self::assertSame('2026-09-21', $template->getDate()->format('Y-m-d'));
        self::assertSame('2026-11-05', $template->getDebitDate()->format('Y-m-d'));
    }

    private function setRecurringCheckDay(int $day): void
    {
        $this->appSettingRepository->getSettings()->setRecurringCheckDay($day);
        $this->entityManager->flush();
    }

    private function isPending(Transaction $template): bool
    {
        foreach ($this->generator->findMonthlyTemplatesPendingNextMonth($template->getAccount()) as $entry) {
            if ($entry['template'] === $template) {
                return true;
            }
        }

        return false;
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
