<?php

namespace EMS\ClientHelperBundle\Helper\Routing;

use EMS\ClientHelperBundle\Helper\Cache\CacheHelper;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequest;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequestManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouteCollection;

class Router extends BaseRouter
{
    /** @var ClientRequestManager */
    private $manager;
    /** @var LoggerInterface */
    private $logger;
    /** @var CacheHelper */
    private $cache;
    /** @var array */
    private $locales;
    /** @var array */
    private $templates;
    /** @var array */
    private $routes;

    public function __construct(ClientRequestManager $manager, CacheHelper $cacheHelper, array $locales, array $templates, array $routes)
    {
        $this->manager = $manager;
        $this->logger = $manager->getLogger();
        $this->cache = $cacheHelper;
        $this->locales = $locales;
        $this->templates = $templates;
        $this->routes = $routes;
    }

    public function getRouteCollection(): RouteCollection
    {
        if (null === $this->collection) {
            $this->buildRouteCollection();
        }

        return $this->collection;
    }

    public function buildRouteCollection(): void
    {
        $collection = new RouteCollection();
        $this->addSearchResultRoute($collection);
        $this->addLanguageSelectionRoute($collection);
        $this->addEMSRoutes($collection);
        $this->addEnvRoutes($collection);

        $this->collection = $collection;
    }

    private function addEnvRoutes(RouteCollection $collection): void
    {
        foreach ($this->routes as $name => $options) {
            $route = new Route('ems_'.$name, $options);
            $route->addToCollection($collection, $this->locales);
        }
    }

    private function addLanguageSelectionRoute(RouteCollection $collection): void
    {
        if (isset($this->templates['language']) && \count($this->locales) > 1) {
            $langSelectRoute = new Route('emsch_language_selection', [
                'path' => '/language-selection',
                'controller' => 'emsch.controller.language_select::view',
                'defaults' => ['template' => $this->templates['language']],
            ]);
            $langSelectRoute->addToCollection($collection);
        }
    }

    private function addSearchResultRoute(RouteCollection $collection): void
    {
        if (isset($this->templates['search'])) {
            @\trigger_error('Deprecated search template use routing content type!', E_USER_DEPRECATED);

            $searchRoute = new Route('emsch_search', [
                'path' => '/{_locale}/search',
                'controller' => 'emsch.controller.search::results',
                'defaults' => ['template' => $this->templates['search']],
            ]);
            $searchRoute->addToCollection($collection);
        }
    }

    private function addEMSRoutes(RouteCollection $collection): void
    {
        foreach ($this->manager->all() as $clientRequest) {
            if (!$clientRequest->hasOption('route_type')) {
                continue;
            }

            if (!$clientRequest->mustBeBind() && !$clientRequest->hasEnvironments()) {
                continue;
            }

            $routes = $this->getRoutes($clientRequest);

            foreach ($routes as $route) {
                /* @var $route Route */
                $route->addToCollection($collection, $this->locales);
            }
        }
    }

    private function getRoutes(ClientRequest $clientRequest): array
    {
        $cacheItem = $this->cache->get($clientRequest->getCacheKey('routes'));

        $type = $clientRequest->getOption('[route_type]');
        $lastChanged = $clientRequest->getLastChangeDate($type);

        if ($this->cache->isValid($cacheItem, $lastChanged)) {
            return $this->cache->getData($cacheItem);
        }

        $routes = $this->createRoutes($clientRequest, $type);
        $this->cache->save($cacheItem, $routes);

        return $routes;
    }

    private function createRoutes(ClientRequest $clientRequest, string $type): array
    {
        $environment = $clientRequest->getCurrentEnvironment();
        $baseUrl = $environment->getBaseUrl();
        $routePrefix = $environment->getRoutePrefix();
        $routes = [];
        $scroll = $clientRequest->scrollAll([
            'size' => 100,
            'type' => $type,
            'sort' => ['order'],
        ], '5s');

        foreach ($scroll as $hit) {
            $source = $hit['_source'];
            $name = $routePrefix.$source['name'];

            try {
                $options = \json_decode($source['config'], true);

                if (JSON_ERROR_NONE !== \json_last_error()) {
                    throw new \InvalidArgumentException(\sprintf('invalid json %s', $source['config']));
                }

                $options['query'] = $source['query'] ?? null;

                $staticTemplate = isset($source['template_static']) ? '@EMSCH/'.$source['template_static'] : null;
                $options['template'] = $source['template_source'] ?? $staticTemplate;
                $options['index_regex'] = $source['index_regex'] ?? null;

                if (\strlen($baseUrl) > 0) {
                    $options['path'] = $this->prependBaseUrl($options['path'] ?? null, $baseUrl);
                }

                $routes[] = new Route($name, $options);
            } catch (\Exception $e) {
                $this->logger->error('Router failed to create ems route {name} : {error}', ['name' => $name, 'error' => $e->getMessage()]);
            }
        }

        return $routes;
    }

    /**
     * @param array<string, string>|string|null $path
     *
     * @return array<string, string>|string
     */
    private function prependBaseUrl($path, string $baseUrl)
    {
        if (\is_array($path)) {
            foreach ($path as $locale => $subpath) {
                $path[$locale] = \sprintf('%s/%s', $baseUrl, '/' === \substr($subpath, 0, 1) ? \substr($subpath, 1) : $subpath);
            }
        } elseif (\is_string($path)) {
            $path = \sprintf('%s/%s', $baseUrl, '/' === \substr($path, 0, 1) ? \substr($path, 1) : $path);
        } else {
            throw new \RuntimeException('Unexpected path type');
        }

        return $path;
    }
}
