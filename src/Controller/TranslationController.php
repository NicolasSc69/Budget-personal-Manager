<?php

namespace App\Controller;

use App\Repository\AppSettingRepository;
use App\Service\CurrencyResolver;
use App\Service\PoTranslationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/translations')]
final class TranslationController extends AbstractController
{
    #[Route(name: 'app_admin_translations', methods: ['GET'])]
    public function index(Request $request, PoTranslationManager $translationManager, AppSettingRepository $appSettingRepository, CurrencyResolver $currencyResolver): Response
    {
        $locales = $translationManager->getAvailableLocales();
        $settings = $appSettingRepository->getSettings();
        $defaultLocale = $settings->getLocale();

        $editedLocale = $request->query->getString('lang', $defaultLocale);
        if (!\in_array($editedLocale, $locales, true)) {
            $editedLocale = PoTranslationManager::SOURCE_LOCALE;
        }

        $activeTab = $request->query->getString('tab', 'languages');
        if (!\in_array($activeTab, ['languages', 'edit'], true)) {
            $activeTab = 'languages';
        }

        $sourceStrings = $translationManager->getSourceStrings();
        sort($sourceStrings, SORT_STRING | SORT_FLAG_CASE);
        $translations = $translationManager->getTranslations($editedLocale);

        $strings = [];
        foreach ($sourceStrings as $sourceString) {
            $strings[] = [
                'key' => md5($sourceString),
                'source' => $sourceString,
                'translation' => $translations[$sourceString] ?? '',
            ];
        }

        $localeStats = [];
        foreach ($locales as $locale) {
            $localeTranslations = PoTranslationManager::SOURCE_LOCALE === $locale ? array_combine($sourceStrings, $sourceStrings) : $translationManager->getTranslations($locale);
            $translatedCount = 0;
            foreach ($sourceStrings as $sourceString) {
                if ('' !== ($localeTranslations[$sourceString] ?? '')) {
                    ++$translatedCount;
                }
            }

            $localeStats[$locale] = [
                'translated' => $translatedCount,
                'total' => \count($sourceStrings),
            ];
        }

        return $this->render('admin/translations.html.twig', [
            'locales' => $locales,
            'localeStats' => $localeStats,
            'defaultLocale' => $defaultLocale,
            'editedLocale' => $editedLocale,
            'strings' => $strings,
            'addableLocales' => $translationManager->getAddableLocales($defaultLocale),
            'activeTab' => $activeTab,
            'currency' => $settings->getCurrency(),
            'currencySymbol' => $currencyResolver->getSymbol($settings->getCurrency()),
            'currencyChoices' => $currencyResolver->getCommonCurrencies(),
            'guessedCurrency' => $currencyResolver->guessCurrencyForLocale($defaultLocale),
        ]);
    }

    #[Route('/save', name: 'app_admin_translations_save', methods: ['POST'])]
    public function save(Request $request, PoTranslationManager $translationManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_translations_save', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $locale = $request->request->getString('locale');
        $locales = $translationManager->getAvailableLocales();

        if (!\in_array($locale, $locales, true) || PoTranslationManager::SOURCE_LOCALE === $locale) {
            $this->addFlash('danger', $translator->trans('This language cannot be edited.'));

            return $this->redirectToRoute('app_admin_translations', ['lang' => $locale, 'tab' => 'edit']);
        }

        $ids = $request->request->all('ids');
        $values = $request->request->all('translations');

        $updates = [];
        foreach ($ids as $key => $sourceString) {
            $updates[$sourceString] = trim((string) ($values[$key] ?? ''));
        }

        try {
            $translationManager->setTranslations($locale, $updates);
        } catch (\RuntimeException) {
            $this->addFlash('danger', $translator->trans('Unable to save: the web server does not have write permission on the translation files.'));

            return $this->redirectToRoute('app_admin_translations', ['lang' => $locale, 'tab' => 'edit']);
        }

        $this->addFlash('success', $translator->trans('Translations have been saved.'));

        return $this->redirectToRoute('app_admin_translations', ['lang' => $locale, 'tab' => 'edit']);
    }

    #[Route('/add-locale', name: 'app_admin_translations_add_locale', methods: ['POST'])]
    public function addLocale(Request $request, PoTranslationManager $translationManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_translations_add_locale', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $locale = strtolower(trim($request->request->getString('locale')));

        if (!preg_match('/^[a-z]{2,3}(_[a-z]{2})?$/', $locale)) {
            $this->addFlash('danger', $translator->trans('Invalid language code.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        if (\in_array($locale, $translationManager->getAvailableLocales(), true)) {
            $this->addFlash('danger', $translator->trans('This language has already been added.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        try {
            $translationManager->addLocale($locale);
        } catch (\RuntimeException) {
            $this->addFlash('danger', $translator->trans('Unable to save: the web server does not have write permission on the translation files.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $this->addFlash('success', $translator->trans('Language added.'));

        return $this->redirectToRoute('app_admin_translations', ['lang' => $locale, 'tab' => 'edit']);
    }

    #[Route('/remove-locale', name: 'app_admin_translations_remove_locale', methods: ['POST'])]
    public function removeLocale(Request $request, PoTranslationManager $translationManager, AppSettingRepository $appSettingRepository, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_translations_remove_locale', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $locale = $request->request->getString('locale');

        if (PoTranslationManager::SOURCE_LOCALE === $locale) {
            $this->addFlash('danger', $translator->trans('The source language cannot be removed.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        if (!\in_array($locale, $translationManager->getAvailableLocales(), true)) {
            $this->addFlash('danger', $translator->trans('Unknown language.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        if ($locale === $appSettingRepository->getSettings()->getLocale()) {
            $this->addFlash('danger', $translator->trans('The default site language cannot be removed.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        try {
            $translationManager->removeLocale($locale);
        } catch (\RuntimeException) {
            $this->addFlash('danger', $translator->trans('Unable to save: the web server does not have write permission on the translation files.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $this->addFlash('success', $translator->trans('Language removed.'));

        return $this->redirectToRoute('app_admin_translations');
    }

    #[Route('/default-locale', name: 'app_admin_translations_default_locale', methods: ['POST'])]
    public function setDefaultLocale(Request $request, PoTranslationManager $translationManager, AppSettingRepository $appSettingRepository, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_translations_default_locale', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $locale = $request->request->getString('locale');

        if (!\in_array($locale, $translationManager->getAvailableLocales(), true)) {
            $this->addFlash('danger', $translator->trans('Unknown language.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $appSettingRepository->getSettings()->setLocale($locale);
        $entityManager->flush();

        $this->addFlash('success', $translator->trans('The default site language has been updated.'));

        return $this->redirectToRoute('app_admin_translations', ['lang' => $locale]);
    }

    #[Route('/currency', name: 'app_admin_translations_currency', methods: ['POST'])]
    public function setCurrency(Request $request, AppSettingRepository $appSettingRepository, CurrencyResolver $currencyResolver, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('admin_translations_currency', $request->request->getString('_token'))) {
            $this->addFlash('danger', $translator->trans('Invalid security token, please try again.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $currency = strtoupper(trim($request->request->getString('currency')));

        if (!$currencyResolver->isKnownCurrency($currency)) {
            $this->addFlash('danger', $translator->trans('Unknown currency code.'));

            return $this->redirectToRoute('app_admin_translations');
        }

        $appSettingRepository->getSettings()->setCurrency($currency);
        $entityManager->flush();

        $this->addFlash('success', $translator->trans('The displayed currency has been updated.'));

        return $this->redirectToRoute('app_admin_translations');
    }
}
