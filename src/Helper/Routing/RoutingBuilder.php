<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Helper\Routing;

use EMS\ClientHelperBundle\Helper\Builder\AbstractBuilder;
use EMS\ClientHelperBundle\Helper\ContentType\ContentType;
use EMS\ClientHelperBundle\Helper\Environment\Environment;
use Symfony\Component\Routing\RouteCollection;

final class RoutingBuilder extends AbstractBuilder
{
    public function buildRouteCollection(Environment $environment): RouteCollection
    {
        $routeCollection = new RouteCollection();

        if (null === $contentType = $this->clientRequest->getRouteContentType($environment)) {
            return $routeCollection;
        }

        foreach ($this->getRoutes($contentType) as $route) {
            $route->addToCollection($routeCollection);
        }

        return $routeCollection;
    }

    public function getLocalRouteCollection(Environment $environment): ?RouteCollection
    {
        if (null === $localHelper = $this->getLocalHelper($environment)) {
            return null;
        }

        $routeCollection = new RouteCollection();

        if (null === $routeConfigs = $localHelper->getRouteConfigs($environment)) {
            return $routeCollection;
        }

        $routes = $this->createRoutes($environment, $routeConfigs);

        foreach ($routes as $route) {
            $route->addToCollection($routeCollection);
        }

        return $routeCollection;
    }

    /**
     * @return RouteConfig[]
     */
    public function buildRouteConfigs(Environment $environment): array
    {
        if (null === $contentType = $this->clientRequest->getRouteContentType($environment)) {
            return [];
        }

        return \array_map(
            fn (array $hit) => RouteConfig::fromArray($hit['_source']['name'], $hit['_source']),
            $this->searchRoutes($contentType)
        );
    }

    /**
     * @return Route[]
     */
    private function getRoutes(ContentType $contentType): array
    {
        if (null !== $cache = $contentType->getCache()) {
            return $cache;
        }

        $routeConfigs = $this->buildRouteConfigs($contentType->getEnvironment());
        $routes = $this->createRoutes($contentType->getEnvironment(), $routeConfigs);
        $contentType->setCache($routes);
        $this->clientRequest->cacheContentType($contentType);

        return $routes;
    }

    /**
     * @param RouteConfig[] $routeConfigs
     *
     * @return Route[]
     */
    private function createRoutes(Environment $environment, array $routeConfigs): array
    {
        $options = [];

        if (null !== $routePrefix = $environment->getRoutePrefix()) {
            $options['prefix'] = $routePrefix;
        }

        return \array_map(
            fn (RouteConfig $config) => new Route($config->getName(), \array_merge($options, $config->getOptions())),
            $routeConfigs
        );
    }

    /**
     * @return array<mixed>
     */
    private function searchRoutes(ContentType $contentType): array
    {
        return $this->search($contentType, [
            'sort' => [
                'order' => [
                    'order' => 'asc',
                    'missing' => '_last',
                    'unmapped_type' => 'long',
                ],
            ],
        ]);
    }
}
