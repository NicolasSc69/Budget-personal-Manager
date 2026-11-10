<?php

namespace App\Tests\Entity;

use App\Entity\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testColorIsNullByDefault(): void
    {
        self::assertNull((new Account())->getColor());
    }

    public function testSetColorStoresTheColorUpperCased(): void
    {
        // The color picker submits lower-case values: storing them upper-cased lets the unique
        // index and the UniqueEntity check treat "#abcdef" and "#ABCDEF" as the same color.
        $account = (new Account())->setColor('#a1b2c3');

        self::assertSame('#A1B2C3', $account->getColor());
    }

    public function testSetColorAcceptsNull(): void
    {
        $account = (new Account())->setColor('#123456')->setColor(null);

        self::assertNull($account->getColor());
    }
}
