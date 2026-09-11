<?php

namespace App\EventListener;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Safety net for every "delete" action in the app: a disabled delete button can
 * always be re-enabled by editing the page's HTML, which resubmits the same
 * request the server would otherwise reject cleanly. If the corresponding
 * controller doesn't already guard against it, the database's own foreign key
 * constraint stops the deletion — but Doctrine's exception would normally
 * bubble up as an uncaught 500. This turns that into the same kind of flash
 * message + redirect the guarded controllers already show.
 */
final class ForeignKeyConstraintExceptionListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RouterInterface $router,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->isForeignKeyViolation($event->getThrowable())) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', $this->translator->trans('This item cannot be deleted because other data still refers to it.'));
        }

        $fallback = $this->router->generate('app_dashboard');

        $event->setResponse(new RedirectResponse($this->resolveSameHostReferer($request->headers->get('referer'), $request->getHost()) ?? $fallback, 303));
        $event->stopPropagation();
    }

    private function isForeignKeyViolation(\Throwable $throwable): bool
    {
        for ($e = $throwable; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof ForeignKeyConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    private function resolveSameHostReferer(?string $referer, string $currentHost): ?string
    {
        if (null === $referer) {
            return null;
        }

        $parts = parse_url($referer);

        if (!isset($parts['host']) || $parts['host'] !== $currentHost) {
            return null;
        }

        return $referer;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Runs after Symfony's own ErrorListener (priority -128) has already
            // logged the exception and built the default error response, so this
            // only needs to replace that response — no need to fight over which
            // listener's setResponse() call "wins".
            KernelEvents::EXCEPTION => ['onKernelException', -200],
        ];
    }
}
