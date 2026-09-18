<?php

namespace App\Tests\Twig;

use App\Entity\AppSetting;
use App\Repository\AccountRepository;
use App\Repository\AppSettingRepository;
use App\Service\CurrencyResolver;
use App\Service\DatabaseBackupService;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AppExtensionTest extends TestCase
{
    private function createExtension(string $currency = 'EUR'): AppExtension
    {
        $appSetting = (new AppSetting())->setCurrency($currency);

        $appSettingRepository = $this->createStub(AppSettingRepository::class);
        $appSettingRepository->method('getSettings')->willReturn($appSetting);

        return new AppExtension(
            $this->createStub(AccountRepository::class),
            $this->createStub(DatabaseBackupService::class),
            $appSettingRepository,
            new CurrencyResolver(),
            $this->createStub(TranslatorInterface::class),
        );
    }

    public function testGetCurrencySymbolReadsTheConfiguredCurrency(): void
    {
        self::assertSame('€', $this->createExtension('EUR')->getCurrencySymbol());
        self::assertSame('$', $this->createExtension('USD')->getCurrencySymbol());
    }

    public function testFormatMoneyAppendsTheCurrentCurrencySymbol(): void
    {
        self::assertSame('1 234,56 €', $this->createExtension('EUR')->formatMoney(1234.56));
        self::assertSame('1 234,56 $', $this->createExtension('USD')->formatMoney('1234.56'));
    }

    public function testFormatMoneyTreatsNullAsZero(): void
    {
        self::assertSame('0,00 €', $this->createExtension('EUR')->formatMoney(null));
    }

    public function testFormatMoneyKeepsTheMinusSignForNegativeAmounts(): void
    {
        self::assertSame('-42,10 €', $this->createExtension('EUR')->formatMoney(-42.1));
    }
}
