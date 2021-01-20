<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Helper\Routing;

use EMS\ClientHelperBundle\Helper\Environment\EnvironmentHelper;
use Symfony\Component\Routing\RouteCollection;

final class Router extends BaseRouter
{
    private EnvironmentHelper $environmentHelper;
    private RoutingBuilder $routeBuilder;

    public function __construct(EnvironmentHelper $environmentHelper, RoutingBuilder $routeBuilder)
    {
        $this->environmentHelper = $environmentHelper;
        $this->routeBuilder = $routeBuilder;
    }

    public function getRouteCollection(): RouteCollection
    {
        if (null === $environment = $this->environmentHelper->getCurrentEnvironment()) {
            return new RouteCollection();
        }

        return $this->routeBuilder->buildRouteCollection($environment);
    }
}
