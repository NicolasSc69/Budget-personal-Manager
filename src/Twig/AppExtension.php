<?php

namespace App\Twig;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\AppSettingRepository;
use App\Service\CurrencyResolver;
use App\Service\DatabaseBackupService;
use App\Service\DatabaseExportTracker;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly DatabaseBackupService $databaseBackupService,
        private readonly DatabaseExportTracker $databaseExportTracker,
        private readonly AppSettingRepository $appSettingRepository,
        private readonly CurrencyResolver $currencyResolver,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('all_accounts', [$this, 'getAllAccounts']),
            new TwigFunction('backup_configured', [$this->databaseBackupService, 'isConfigured']),
            new TwigFunction('database_modified_since_export', [$this->databaseExportTracker, 'hasChangesSinceExport']),
            new TwigFunction('currency_symbol', [$this, 'getCurrencySymbol']),
            new TwigFunction('default_theme', [$this, 'getDefaultTheme']),
            new TwigFunction('account_nav_message', [$this, 'getAccountNavMessage']),
            new TwigFunction('next_month_name', [$this, 'getNextMonthName']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', [$this, 'formatMoney']),
        ];
    }

    /**
     * @return Account[]
     */
    public function getAllAccounts(): array
    {
        return $this->accountRepository->findAllOrderedByLabel();
    }

    public function getCurrencySymbol(): string
    {
        return $this->currencyResolver->getSymbol($this->appSettingRepository->getSettings()->getCurrency());
    }

    public function getDefaultTheme(): string
    {
        return $this->appSettingRepository->getSettings()->getTheme();
    }

    public function formatMoney(int|float|string|null $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ').' '.$this->getCurrencySymbol();
    }

    public function getAccountNavMessage(Account $account): string
    {
        $label = $account->getLabel();
        if (null !== $account->getPerson()) {
            $label .= ' ('.$account->getPerson()->getName().')';
        }

        return $this->translator->trans('Redirecting to %account%', ['%account%' => $label]);
    }

    /**
     * Localized name of the month following the current one (e.g. "novembre" in October),
     * i.e. the month recurring transactions get duplicated to.
     */
    public function getNextMonthName(): string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMMM');

        return (string) $formatter->format(new \DateTimeImmutable('first day of next month'));
    }
}
