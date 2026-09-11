<?php

namespace App\EventListener;

use App\Repository\AppSettingRepository;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the site-wide default locale (configured on the admin translations page)
 * to every request, before Symfony's own LocaleAwareListener propagates it to the translator.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AppSettingRepository $appSettingRepository,
        private readonly string $defaultLocale,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $settings = $this->appSettingRepository->findExistingSettings();
        $event->getRequest()->setLocale($settings?->getLocale() ?? $this->defaultLocale);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }
}
