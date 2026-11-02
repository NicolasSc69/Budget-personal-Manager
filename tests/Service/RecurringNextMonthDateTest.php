<?php

namespace App\Tests\Service;

use App\Service\RecurringTransactionGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure date logic (no database) behind the manual monthly duplication page.
 */
final class RecurringNextMonthDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string, string, string}>
     */
    public static function provideCases(): iterable
    {
        // today, anchor day, expected next date, expected window start, expected window end (exclusive)
        yield 'weekday is kept as is' => ['2026-11-02', 15, '2026-12-15', '2026-12-01', '2027-01-01'];
        yield 'sunday moves to monday' => ['2026-11-02', 6, '2026-12-07', '2026-12-01', '2027-01-01'];
        yield 'monday on the 1st is not shifted' => ['2027-01-10', 1, '2027-02-01', '2027-02-01', '2027-03-01'];
        yield 'day 31 is clamped to the end of a shorter month' => ['2026-01-15', 31, '2026-02-28', '2026-02-01', '2026-03-01'];
        yield 'sunday at month end crosses into the month after' => ['2027-01-10', 28, '2027-03-01', '2027-02-01', '2027-03-02'];
        yield 'this month shifted occurrence on the 1st is excluded' => ['2027-01-10', 31, '2027-03-01', '2027-02-02', '2027-03-02'];
        yield 'time of day is ignored' => ['2026-11-02 18:45', 6, '2026-12-07', '2026-12-01', '2027-01-01'];
        yield 'december rolls over to next year' => ['2026-12-20', 10, '2027-01-11', '2027-01-01', '2027-02-01'];
    }

    #[DataProvider('provideCases')]
    public function testComputeNextMonthOccurrence(string $today, int $anchorDay, string $nextDate, string $windowStart, string $windowEnd): void
    {
        $result = RecurringTransactionGenerator::computeNextMonthOccurrence(new \DateTimeImmutable($today), $anchorDay);

        self::assertSame($nextDate, $result['nextDate']->format('Y-m-d'));
        self::assertSame($windowStart, $result['windowStart']->format('Y-m-d'));
        self::assertSame($windowEnd, $result['windowEnd']->format('Y-m-d'));
    }

    public function testNextDateIsNeverASunday(): void
    {
        $today = new \DateTimeImmutable('2026-01-01');

        for ($month = 0; $month < 24; ++$month) {
            for ($day = 1; $day <= 31; ++$day) {
                $result = RecurringTransactionGenerator::computeNextMonthOccurrence($today->modify("+$month months"), $day);

                self::assertNotSame('7', $result['nextDate']->format('N'));
                self::assertGreaterThan($result['windowStart'], $result['windowEnd']);
                self::assertGreaterThanOrEqual($result['windowStart'], $result['nextDate']);
                self::assertLessThan($result['windowEnd'], $result['nextDate']);
            }
        }
    }
}
