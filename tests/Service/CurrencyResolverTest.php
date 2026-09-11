<?php

namespace App\Tests\Service;

use App\Service\CurrencyResolver;
use PHPUnit\Framework\TestCase;

final class CurrencyResolverTest extends TestCase
{
    private CurrencyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CurrencyResolver();
    }

    public function testGetSymbolResolvesKnownIsoCodes(): void
    {
        self::assertSame('€', $this->resolver->getSymbol('EUR'));
        self::assertSame('$', $this->resolver->getSymbol('USD'));
        self::assertSame('£', $this->resolver->getSymbol('GBP'));
    }

    public function testGetSymbolFallsBackToTheCodeItselfWhenUnknown(): void
    {
        self::assertSame('ZZZ', $this->resolver->getSymbol('ZZZ'));
    }

    public function testGuessCurrencyForLocaleUsesTheLookupTable(): void
    {
        self::assertSame('EUR', $this->resolver->guessCurrencyForLocale('fr'));
        self::assertSame('USD', $this->resolver->guessCurrencyForLocale('en'));
        self::assertSame('JPY', $this->resolver->guessCurrencyForLocale('ja'));
    }

    public function testGuessCurrencyForLocaleDefaultsToEurForAnUnknownLocale(): void
    {
        self::assertSame('EUR', $this->resolver->guessCurrencyForLocale('xx'));
    }

    public function testGetCommonCurrenciesReturnsCodesMappedToTheirSymbol(): void
    {
        $currencies = $this->resolver->getCommonCurrencies();

        self::assertArrayHasKey('EUR', $currencies);
        self::assertSame('€', $currencies['EUR']);
        self::assertArrayHasKey('USD', $currencies);
        self::assertSame('$', $currencies['USD']);
    }

    public function testIsKnownCurrencyAcceptsRecognizedIsoCodes(): void
    {
        self::assertTrue($this->resolver->isKnownCurrency('EUR'));
        self::assertTrue($this->resolver->isKnownCurrency('USD'));
    }

    public function testIsKnownCurrencyRejectsMalformedInput(): void
    {
        self::assertFalse($this->resolver->isKnownCurrency('eur'));
        self::assertFalse($this->resolver->isKnownCurrency('EU'));
        self::assertFalse($this->resolver->isKnownCurrency('EURO'));
        self::assertFalse($this->resolver->isKnownCurrency(''));
    }

    public function testIsKnownCurrencyRejectsAMadeUpIsoCode(): void
    {
        self::assertFalse($this->resolver->isKnownCurrency('ZZZ'));
    }

    public function testIsKnownCurrencyRejectsTheNoCurrencyCode(): void
    {
        // "XXX" is technically valid ISO 4217 (it means "no currency"), but
        // it has no real symbol and must not be accepted as a display currency.
        self::assertFalse($this->resolver->isKnownCurrency('XXX'));
    }
}
