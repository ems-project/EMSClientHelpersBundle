<?php

namespace EMS\ClientHelperBundle\EventListener;

use EMS\ClientHelperBundle\Service\LanguageSelectionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

class LocaleListener implements EventSubscriberInterface
{
    /**
     * @var LanguageSelectionService
     */
    private $languageService;

    /**
     * @var HttpKernel
     */
    private $kernel;

    /**
     * @var Router
     */
    private $router;

    /**
     * @param LanguageSelectionService $languageService
     * @param HttpKernel               $kernel
     * @param RouterInterface          $router
     */
    public function __construct(
        LanguageSelectionService $languageService,
        HttpKernel $kernel,
        RouterInterface $router
    ) {
        $this->languageService = $languageService;
        $this->kernel = $kernel;
        $this->router = $router;
    }

    /**
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if (preg_match('/(emsch_api_).*/', $request->attributes->get('_route'))) {
            return;
        }

        $locale = $this->checkLocale($request);

        if ($locale) {
            $event->getRequest()->getSession()->set('_locale', $locale);
        }
    }

    /**
     * @param GetResponseForExceptionEvent $event
     *
     * @return void
     */
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        if (!$event->isMasterRequest()) {
            // don't do anything if it's not the master request
            return;
        }

        $exception = $event->getException();

        if ($exception instanceof NotFoundHttpException) {
            $this->handleNotFoundException($event);
            return;
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 30]],
            KernelEvents::EXCEPTION => [['onKernelException', 30]],
        ];
    }

    /**
     * @param GetResponseForExceptionEvent $event
     *
     * @return void
     */
    private function handleNotFoundException(GetResponseForExceptionEvent $event)
    {
        $request = $event->getRequest();

        if (preg_match('/(emsch_api_).*/', $request->attributes->get('_route'))) {
            return;
        }

        if (false === $this->checkLocale($request)) {
            $this->redirectMissingLocale($event);
            return;
        }
    }

    /**
     * @param GetResponseForExceptionEvent $event
     */
    private function redirectMissingLocale(GetResponseForExceptionEvent $event)
    {
        $request = $event->getRequest();
        $session = $request->getSession();
        $destination = $request->getPathInfo();

        if ('' === $destination || '/' === $destination) {
            $destination = null;
        }

        $languages = $this->languageService->getLanguages();

        if ($session->has('_locale')) {
            $url = $request->getUriForPath('/'.$session->get('_locale').$destination);
        } elseif (1 === count($languages)) {
            $url = $request->getUriForPath('/'.$languages[0].$destination);
        } else {
            $url = $this->router->generate('emsch_language_selection', [
                'destination' => $destination
            ]);
        }

        $response = new RedirectResponse($url);
        $event->setResponse($response);
    }

    /**
     * @param Request $request
     *
     * @return string|false
     */
    private function checkLocale(Request $request)
    {
        $locale = $request->attributes->get('_locale', false);

        if ($locale) {
            return $locale;
        }

        $localeUri = $this->getLocaleUri($request->getPathInfo());

        if ($localeUri) {
            $request->setLocale($localeUri);
            return $localeUri;
        }

        return false;
    }

    /**
     * @param string $uri
     *
     * @return string|false
     */
    private function getLocaleUri($uri)
    {
        $supportedLocale = $this->languageService->getLanguages();

        $regex = sprintf('/^\/(?P<locale>%s).*$/', implode('|', $supportedLocale));
        preg_match($regex, $uri, $matches);

        return (isset($matches['locale']) ? $matches['locale'] : false);
    }
}
