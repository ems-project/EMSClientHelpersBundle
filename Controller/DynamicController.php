<?php

namespace EMS\ClientHelperBundle\Controller;

use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequestManager;
use EMS\ClientHelperBundle\Exception\SingleResultException;
use EMS\ClientHelperBundle\Helper\Twig\TwigLoader;
use EMS\CommonBundle\Common\EMSLink;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;


class DynamicController
{
    /**
     * @var ClientRequestManager
     */
    private $manager;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var \Twig_Environment
     */
    private $templating;

    /**
     * @param ClientRequestManager $manager
     * @param RouterInterface      $router
     * @param \Twig_Environment    $templating
     */
    public function __construct(ClientRequestManager $manager, RouterInterface $router, \Twig_Environment $templating)
    {
        $this->manager = $manager;
        $this->router = $router;
        $this->templating = $templating;
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function handle(Request $request)
    {
        $route = $this->getRoute($request);
        $type = $route->getOption('type');

        try {
            $client = $this->manager->getDefault();

            $body = $this->createSearchBody($request, $route);
            $document = $client->searchOne($type, $body);
            $template = $this->getTemplate($route, $document);

            $content = $this->templating->render($template, [
                'document' => $document,
                'source' => $document['_source'],
                'emsLink' => EMSLink::fromDocument($document),
                'trans_default_domain' => $client->getNameEnv(),
            ]);

            return new Response($content, 200);
        } catch (SingleResultException $e) {
            throw new NotFoundHttpException();
        }
    }


    /**
     * @param Request $request
     *
     * @return null|Route
     */
    private function getRoute(Request $request)
    {
        $name = $request->attributes->get('_route');
        $route = $this->router->getRouteCollection()->get($name);

        if (null === $route) {
            throw new NotFoundHttpException('ems route not found');
        }

        return $route;
    }

    /**
     * Replaces all %values% parameters in the route query string,
     * with request attributes.
     *
     * @param Request $request
     * @param Route   $route
     *
     * @return array
     */
    private function createSearchBody(Request $request, Route $route)
    {
        $pattern = '/%(?<parameter>(_|)[[:alnum:]]*)%/m';

        $json = preg_replace_callback($pattern, function ($match) use ($request) {
            return $request->get($match['parameter'], $match[0]);
        }, $route->getOption('query'));

        return json_decode($json, true);
    }

    /**
     * @param Route $route
     * @param array $document
     *
     * @return string
     */
    private function getTemplate(Route $route, array $document)
    {
        $template = $route->getOption('template');

        if (substr($template, 0, 6) === TwigLoader::PREFIX) {
            return $template;
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        return TwigLoader::PREFIX . '/' . $propertyAccessor->getValue($document, '[_source]'.$template);
    }
}