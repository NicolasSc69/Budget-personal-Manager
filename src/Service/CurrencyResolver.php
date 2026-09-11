<?php

namespace App\Service;

/**
 * Resolves ISO 4217 currency codes to display symbols and offers a starting
 * guess for a given site locale.
 *
 * App locales are bare language codes (e.g. "fr", "en" — see
 * PoTranslationManager), never a full "fr_FR"-style region tag, so ICU has
 * no country to derive a currency from (NumberFormatter falls back to the
 * meaningless "XXX" code for those). GUESS_BY_LOCALE is therefore only a
 * best-effort default to pre-select in the admin UI; the actual currency
 * always remains a manually confirmed/overridable setting.
 */
final class CurrencyResolver
{
    private const array GUESS_BY_LOCALE = [
        'fr' => 'EUR', 'de' => 'EUR', 'es' => 'EUR', 'it' => 'EUR', 'nl' => 'EUR',
        'pt' => 'EUR', 'el' => 'EUR', 'fi' => 'EUR', 'sk' => 'EUR', 'sl' => 'EUR',
        'en' => 'USD', 'ja' => 'JPY', 'zh' => 'CNY', 'ru' => 'RUB', 'ko' => 'KRW',
        'pl' => 'PLN', 'sv' => 'SEK', 'no' => 'NOK', 'nb' => 'NOK', 'da' => 'DKK',
        'cs' => 'CZK', 'ro' => 'RON', 'hu' => 'HUF', 'tr' => 'TRY', 'he' => 'ILS',
        'hi' => 'INR', 'th' => 'THB', 'vi' => 'VND', 'id' => 'IDR', 'uk' => 'UAH',
        'ar' => 'SAR',
    ];

    /**
     * Common ISO 4217 codes offered in the admin currency picker, in display
     * order. Not exhaustive (PHP's intl extension does not expose the full
     * ISO 4217 list), but any other valid code can still be typed manually.
     */
    private const array COMMON_CURRENCIES = [
        'EUR', 'USD', 'GBP', 'CHF', 'CAD', 'AUD', 'JPY', 'CNY', 'INR', 'BRL',
        'MXN', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'RON', 'HUF', 'TRY', 'ZAR',
        'XOF', 'XAF', 'MAD', 'AED', 'SAR', 'ILS', 'RUB', 'KRW', 'THB', 'VND',
        'IDR', 'UAH',
    ];

    public function guessCurrencyForLocale(string $locale): string
    {
        return self::GUESS_BY_LOCALE[$locale] ?? 'EUR';
    }

    /**
     * @return array<string, string> ISO 4217 code => symbol, for the curated common-currency list
     */
    public function getCommonCurrencies(): array
    {
        $currencies = [];
        foreach (self::COMMON_CURRENCIES as $code) {
            $currencies[$code] = $this->getSymbol($code);
        }

        return $currencies;
    }

    /**
     * "XXX" is technically a valid ISO 4217 code (it means "no currency"),
     * so ICU happily resolves it to the generic currency sign "¤" instead of
     * falling back to the code like it does for made-up codes — it must be
     * rejected explicitly here, otherwise it would pass validation and then
     * display as a meaningless "¤" throughout the app.
     */
    private const string GENERIC_CURRENCY_SIGN = "\u{A4}";

    public function isKnownCurrency(string $currencyCode): bool
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            return false;
        }

        $symbol = $this->getSymbol($currencyCode);

        return $symbol !== $currencyCode && self::GENERIC_CURRENCY_SIGN !== $symbol;
    }

    public function getSymbol(string $currencyCode): string
    {
        $formatter = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currencyCode);
        $symbol = $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);

        return '' !== $symbol ? $symbol : $currencyCode;
    }
}
