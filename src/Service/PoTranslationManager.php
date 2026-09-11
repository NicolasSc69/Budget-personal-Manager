<?php

namespace App\Service;

use Symfony\Component\Translation\Dumper\PoFileDumper;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Reads and writes the translations/messages.*.po files backing the admin
 * "Translate interface" page, in the same spirit as Drupal's locale module:
 * the English source string doubles as the translation key.
 */
final class PoTranslationManager
{
    public const string SOURCE_LOCALE = 'en';
    public const string DOMAIN = 'messages';

    private readonly PoFileLoader $loader;
    private readonly PoFileDumper $dumper;

    public function __construct(
        private readonly string $translationsDir,
        private readonly string $localesConfigFile,
    ) {
        $this->loader = new PoFileLoader();
        $this->dumper = new PoFileDumper();
    }

    /**
     * Derived from the .po files actually present in translations/, never
     * from the compiled kernel.enabled_locales container parameter: in prod
     * that parameter is frozen at the last cache:clear, so a language added
     * or removed at runtime would otherwise never show up (or keep showing
     * up) until someone manually cleared the cache. The source locale
     * always comes first.
     *
     * @return string[]
     */
    public function getAvailableLocales(): array
    {
        $locales = [];

        foreach (glob(rtrim($this->translationsDir, '/').'/'.self::DOMAIN.'.*.po') ?: [] as $path) {
            if (preg_match('/\.([a-z]{2,3}(?:_[a-z]{2})?)\.po$/', $path, $matches)) {
                $locales[] = $matches[1];
            }
        }

        sort($locales, SORT_STRING);

        $sourceIndex = array_search(self::SOURCE_LOCALE, $locales, true);
        if (false !== $sourceIndex) {
            unset($locales[$sourceIndex]);
            array_unshift($locales, self::SOURCE_LOCALE);
        }

        return array_values($locales);
    }

    /**
     * UN M49 macro-region code for each ICU-known language, i.e. the
     * continent grouping used by the "Add a language" dropdown. Precomputed
     * (rather than resolved at runtime via CLDR "likely subtag" data) because
     * the API to do that, `Locale::addLikelySubtags()`, only exists from PHP
     * 8.5 onwards while this app supports PHP >= 8.4 — calling it there is a
     * fatal "call to undefined method" error. A language missing from this
     * table falls back to the "001" World group rather than being dropped.
     */
    private const array CONTINENT_BY_LOCALE = [
        'af' => '002', 'agq' => '002', 'ak' => '002', 'am' => '002', 'ar' => '002', 'as' => '142',
        'asa' => '002', 'ast' => '150', 'az' => '142', 'bas' => '002', 'be' => '150', 'bem' => '002',
        'bez' => '002', 'bg' => '150', 'bgc' => '142', 'bho' => '142', 'blo' => '002', 'bm' => '002',
        'bn' => '142', 'bo' => '142', 'br' => '150', 'brx' => '142', 'bs' => '150', 'ca' => '150',
        'ccp' => '142', 'ce' => '150', 'ceb' => '142', 'cgg' => '002', 'chr' => '019', 'ckb' => '142',
        'cs' => '150', 'csw' => '019', 'cv' => '150', 'cy' => '150', 'da' => '150', 'dav' => '002',
        'de' => '150', 'dje' => '002', 'doi' => '142', 'dsb' => '150', 'dua' => '002', 'dyo' => '002',
        'dz' => '142', 'ebu' => '002', 'ee' => '002', 'el' => '150', 'en' => '019', 'eo' => '001',
        'es' => '150', 'et' => '150', 'eu' => '150', 'ewo' => '002', 'fa' => '142', 'ff' => '002',
        'fi' => '150', 'fil' => '142', 'fo' => '150', 'fr' => '150', 'fur' => '150', 'fy' => '150',
        'ga' => '150', 'gd' => '150', 'gl' => '150', 'gsw' => '150', 'gu' => '142', 'guz' => '002',
        'gv' => '150', 'ha' => '002', 'haw' => '019', 'he' => '142', 'hi' => '142', 'hr' => '150',
        'hsb' => '150', 'hu' => '150', 'hy' => '142', 'ia' => '001', 'id' => '142', 'ie' => '150',
        'ig' => '002', 'ii' => '142', 'is' => '150', 'it' => '150', 'ja' => '142', 'jgo' => '002',
        'jmc' => '002', 'jv' => '142', 'ka' => '142', 'kab' => '002', 'kam' => '002', 'kde' => '002',
        'kea' => '002', 'kgp' => '019', 'khq' => '002', 'ki' => '002', 'kk' => '142', 'kkj' => '002',
        'kl' => '019', 'kln' => '002', 'km' => '142', 'kn' => '142', 'ko' => '142', 'kok' => '142',
        'ks' => '142', 'ksb' => '002', 'ksf' => '002', 'ksh' => '150', 'ku' => '142', 'kw' => '150',
        'kxv' => '142', 'ky' => '142', 'lag' => '002', 'lb' => '150', 'lg' => '002', 'lij' => '150',
        'lkt' => '019', 'lmo' => '150', 'ln' => '002', 'lo' => '142', 'lrc' => '142', 'lt' => '150',
        'lu' => '002', 'luo' => '002', 'luy' => '002', 'lv' => '150', 'mai' => '142', 'mas' => '002',
        'mer' => '002', 'mfe' => '002', 'mg' => '002', 'mgh' => '002', 'mgo' => '002', 'mi' => '009',
        'mk' => '150', 'ml' => '142', 'mn' => '142', 'mni' => '142', 'mr' => '142', 'ms' => '142',
        'mt' => '150', 'mua' => '002', 'my' => '142', 'mzn' => '142', 'naq' => '002', 'nb' => '150',
        'nd' => '002', 'nds' => '150', 'ne' => '142', 'nl' => '150', 'nmg' => '002', 'nn' => '150',
        'nnh' => '002', 'no' => '150', 'nqo' => '002', 'nus' => '002', 'nyn' => '002', 'oc' => '150',
        'om' => '002', 'or' => '142', 'os' => '142', 'pa' => '142', 'pcm' => '002', 'pl' => '150',
        'prg' => '150', 'ps' => '142', 'pt' => '019', 'qu' => '019', 'raj' => '142', 'rm' => '150',
        'rn' => '002', 'ro' => '150', 'rof' => '002', 'ru' => '150', 'rw' => '002', 'rwk' => '002',
        'sa' => '142', 'sah' => '150', 'saq' => '002', 'sat' => '142', 'sbp' => '002', 'sc' => '150',
        'sd' => '142', 'se' => '150', 'seh' => '002', 'ses' => '002', 'sg' => '002', 'shi' => '002',
        'si' => '142', 'sk' => '150', 'sl' => '150', 'smn' => '150', 'sn' => '002', 'so' => '002',
        'sq' => '150', 'sr' => '150', 'su' => '142', 'sv' => '150', 'sw' => '002', 'syr' => '142',
        'szl' => '150', 'ta' => '142', 'te' => '142', 'teo' => '002', 'tg' => '142', 'th' => '142',
        'ti' => '002', 'tk' => '142', 'to' => '009', 'tok' => '001', 'tr' => '142', 'tt' => '150',
        'twq' => '002', 'tzm' => '002', 'ug' => '142', 'uk' => '150', 'ur' => '142', 'uz' => '142',
        'vai' => '002', 'vec' => '150', 'vi' => '142', 'vmw' => '002', 'vun' => '002', 'wae' => '150',
        'wo' => '002', 'xh' => '002', 'xnr' => '142', 'xog' => '002', 'yav' => '002', 'yi' => '150',
        'yo' => '002', 'yrl' => '019', 'yue' => '142', 'za' => '142', 'zgh' => '002', 'zh' => '142',
        'zu' => '002',
    ];

    private const string WORLD_CONTINENT = '001';

    /**
     * All ICU-known languages not yet added, grouped by continent and
     * localized in $displayLocale. Powers the "Add a language" dropdown.
     * Both the continent groups and the languages within each group are
     * sorted alphabetically in $displayLocale.
     *
     * @return array<string, array{label: string, locales: array<string, string>}> continent code => group
     */
    public function getAddableLocales(string $displayLocale): array
    {
        $groups = [];

        foreach (\ResourceBundle::getLocales('') as $code) {
            if (!preg_match('/^[a-z]{2,3}$/', $code) || \in_array($code, $this->getAvailableLocales(), true)) {
                continue;
            }

            $name = \Locale::getDisplayLanguage($code, $displayLocale);

            if ('' === $name) {
                continue;
            }

            $continent = $this->continentForLocale($code);

            if (!isset($groups[$continent])) {
                $groups[$continent] = [
                    'label' => $this->capitalize(\Locale::getDisplayRegion('und-'.$continent, $displayLocale)),
                    'locales' => [],
                ];
            }

            $groups[$continent]['locales'][$code] = $this->capitalize($name);
        }

        $collator = new \Collator($displayLocale);

        foreach ($groups as &$group) {
            uasort($group['locales'], static fn (string $a, string $b): int => $collator->compare($a, $b));
        }
        unset($group);

        uasort($groups, static fn (array $a, array $b): int => $collator->compare($a['label'], $b['label']));

        return $groups;
    }

    private function continentForLocale(string $code): string
    {
        return self::CONTINENT_BY_LOCALE[$code] ?? self::WORLD_CONTINENT;
    }

    private function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Adds a brand-new language: generates its .po file from the English
     * source catalogue (same keys, empty translations) and registers the
     * locale in framework.enabled_locales so it becomes available on the
     * next request.
     */
    public function addLocale(string $locale): void
    {
        $locale = strtolower(trim($locale));

        if (!preg_match('/^[a-z]{2,3}(_[a-z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException(\sprintf('Invalid locale code "%s".', $locale));
        }

        if (\in_array($locale, $this->getAvailableLocales(), true)) {
            throw new \InvalidArgumentException(\sprintf('Locale "%s" is already available.', $locale));
        }

        $messages = array_fill_keys($this->getSourceStrings(), '');
        $this->writeCatalogue($locale, $messages);

        $this->enableLocaleInConfig($locale);
    }

    /**
     * Removes a language: deletes its .po file and un-registers the locale
     * from framework.enabled_locales. The source locale can never be removed.
     */
    public function removeLocale(string $locale): void
    {
        if (self::SOURCE_LOCALE === $locale) {
            throw new \InvalidArgumentException('The source locale cannot be removed.');
        }

        if (!\in_array($locale, $this->getAvailableLocales(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown locale "%s".', $locale));
        }

        $path = $this->getPath($locale);

        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException(\sprintf('Could not delete "%s": check that the web server has write permission on the translations directory.', $path));
        }

        $this->disableLocaleInConfig($locale);
    }

    private function enableLocaleInConfig(string $locale): void
    {
        $this->updateLocalesInConfig(static function (array $locales) use ($locale): array {
            if (\in_array($locale, $locales, true)) {
                return $locales;
            }

            $locales[] = $locale;

            return $locales;
        });
    }

    private function disableLocaleInConfig(string $locale): void
    {
        $this->updateLocalesInConfig(static fn (array $locales): array => array_values(array_diff($locales, [$locale])));
    }

    /**
     * @param callable(string[]): string[] $update
     */
    private function updateLocalesInConfig(callable $update): void
    {
        if (!is_file($this->localesConfigFile)) {
            return;
        }

        $content = file_get_contents($this->localesConfigFile);
        $pattern = '/enabled_locales:\s*\[([^\]]*)\]/';

        if (!preg_match($pattern, $content, $matches)) {
            return;
        }

        $locales = array_map(
            static fn (string $item): string => trim($item, " '\""),
            explode(',', $matches[1]),
        );

        $newList = implode(', ', array_map(static fn (string $item): string => "'{$item}'", $update($locales)));

        $content = preg_replace($pattern, "enabled_locales: [{$newList}]", $content, 1);

        if (false === @file_put_contents($this->localesConfigFile, $content)) {
            throw new \RuntimeException(\sprintf('Could not write to "%s": check that the web server has write permission on this file.', $this->localesConfigFile));
        }
    }

    /**
     * The full list of translatable source strings, taken from the English
     * catalogue since English is the reference language for this app.
     *
     * @return string[]
     */
    public function getSourceStrings(): array
    {
        return array_keys($this->loadCatalogue(self::SOURCE_LOCALE));
    }

    /**
     * @return array<string, string> msgid => msgstr for the given locale
     */
    public function getTranslations(string $locale): array
    {
        return $this->loadCatalogue($locale);
    }

    public function setTranslation(string $locale, string $msgid, string $msgstr): void
    {
        if (!\in_array($locale, $this->getAvailableLocales(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown locale "%s".', $locale));
        }

        $messages = $this->loadCatalogue($locale);
        $messages[$msgid] = $msgstr;
        ksort($messages);

        $this->writeCatalogue($locale, $messages);
    }

    /**
     * @param array<string, string> $translations msgid => msgstr
     */
    public function setTranslations(string $locale, array $translations): void
    {
        if (!\in_array($locale, $this->getAvailableLocales(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown locale "%s".', $locale));
        }

        $messages = $this->loadCatalogue($locale);

        foreach ($translations as $msgid => $msgstr) {
            $messages[$msgid] = $msgstr;
        }

        ksort($messages);

        $this->writeCatalogue($locale, $messages);
    }

    /**
     * Registers a new source string with an identity translation in every
     * catalogue that doesn't already know about it. Used when new text is
     * introduced in the application so it immediately shows up on the
     * translation page instead of silently falling back untranslated.
     */
    public function ensureSourceString(string $msgid): void
    {
        foreach ($this->getAvailableLocales() as $locale) {
            $messages = $this->loadCatalogue($locale);

            if (!\array_key_exists($msgid, $messages)) {
                $messages[$msgid] = self::SOURCE_LOCALE === $locale ? $msgid : '';
                ksort($messages);
                $this->writeCatalogue($locale, $messages);
            }
        }
    }

    private function loadCatalogue(string $locale): array
    {
        $path = $this->getPath($locale);

        if (!is_file($path)) {
            return [];
        }

        $catalogue = $this->loader->load($path, $locale, self::DOMAIN);

        return $catalogue->all(self::DOMAIN);
    }

    private function writeCatalogue(string $locale, array $messages): void
    {
        $catalogue = new MessageCatalogue($locale);
        $catalogue->add($messages, self::DOMAIN);

        $content = $this->dumper->formatCatalogue($catalogue, self::DOMAIN);

        if (!is_dir($this->translationsDir)) {
            mkdir($this->translationsDir, 0775, true);
        }

        $path = $this->getPath($locale);

        if (false === @file_put_contents($path, $content)) {
            throw new \RuntimeException(\sprintf('Could not write to "%s": check that the web server has write permission on the translations directory.', $path));
        }
    }

    private function getPath(string $locale): string
    {
        return rtrim($this->translationsDir, '/').'/'.self::DOMAIN.'.'.$locale.'.po';
    }
}
