<?php

namespace App\Tests\Entity;

use App\Entity\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deferred debit rule (no database): up to the 20th, debited on the 5th of the next month;
 * from the 21st, on the 5th of the month after next.
 */
final class TransactionTest extends TestCase
{
    #[DataProvider('provideDeferredDebitDates')]
    public function testComputeDeferredDebitDate(string $transactionDate, string $expectedDebitDate): void
    {
        self::assertSame(
            $expectedDebitDate,
            Transaction::computeDeferredDebitDate(new \DateTimeImmutable($transactionDate))->format('Y-m-d'),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideDeferredDebitDates(): iterable
    {
        yield '1st → 5th of next month' => ['2026-01-01', '2026-02-05'];
        yield '20th (last day of the 1st period) → 5th of next month' => ['2026-01-20', '2026-02-05'];
        yield '21st (first day of the 2nd period) → 5th of the month after next' => ['2026-01-21', '2026-03-05'];
        yield 'last day of a 31-day month' => ['2026-01-31', '2026-03-05'];
        yield 'end of February (non-leap year)' => ['2026-02-28', '2026-04-05'];
        yield 'end of February (leap year)' => ['2028-02-29', '2028-04-05'];
        yield '30th of a 30-day month' => ['2026-04-30', '2026-06-05'];
        yield 'December up to the 20th → January of next year' => ['2026-12-20', '2027-01-05'];
        yield 'December from the 21st → February of next year' => ['2026-12-21', '2027-02-05'];
        yield 'November from the 21st → January of next year' => ['2026-11-30', '2027-01-05'];
    }

    public function testDeferredDebitDateIsNeverBeforeTheTransactionDateAndAlwaysOnThe5th(): void
    {
        $date = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2028-12-31');

        for (; $date <= $end; $date = $date->modify('+1 day')) {
            $debitDate = Transaction::computeDeferredDebitDate($date);

            self::assertSame('05', $debitDate->format('d'), $date->format('Y-m-d'));
            self::assertGreaterThan($date, $debitDate, $date->format('Y-m-d'));

            $monthsAhead = ((int) $debitDate->format('Y') - (int) $date->format('Y')) * 12 + (int) $debitDate->format('n') - (int) $date->format('n');
            self::assertSame((int) $date->format('j') <= 20 ? 1 : 2, $monthsAhead, $date->format('Y-m-d'));
        }
    }

    public function testEffectiveDateIsTheDebitDateForADeferredPayment(): void
    {
        $transaction = (new Transaction())->setDate(new \DateTimeImmutable('2026-01-25'));
        self::assertSame('2026-01-25', $transaction->getEffectiveDate()->format('Y-m-d'));

        $transaction->setIsDeferredDebit(true)->setDebitDate(new \DateTimeImmutable('2026-03-05'));
        self::assertSame('2026-03-05', $transaction->getEffectiveDate()->format('Y-m-d'));
    }
}
