<?php

namespace App\Twig;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\AppSettingRepository;
use App\Service\CurrencyResolver;
use App\Service\DatabaseBackupService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly DatabaseBackupService $databaseBackupService,
        private readonly AppSettingRepository $appSettingRepository,
        private readonly CurrencyResolver $currencyResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('all_accounts', [$this, 'getAllAccounts']),
            new TwigFunction('backup_configured', [$this->databaseBackupService, 'isConfigured']),
            new TwigFunction('currency_symbol', [$this, 'getCurrencySymbol']),
            new TwigFunction('default_theme', [$this, 'getDefaultTheme']),
            new TwigFunction('account_nav_message', [$this, 'getAccountNavMessage']),
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
}
