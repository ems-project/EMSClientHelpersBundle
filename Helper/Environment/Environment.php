<?php

namespace EMS\ClientHelperBundle\Helper\Environment;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;

class Environment
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $regex;

    /**
     * @var string
     */
    private $index;

    /**
     * @var string
     */
    private $backend;

    /**
     * @var array
     */
    private $request = [];
    private string $baseUrl;
    private string $routePrefix;
    /** @var array<mixed> */
    private array $options;

    public function __construct(string $name, array $config)
    {
        $this->name = $name;

        $this->regex = $config['regex'] ?? '/.*/';
        $this->baseUrl = $config['base_url'] ?? '';
        $this->routePrefix = $config['route_prefix'] ?? '';
        $this->backend = $config['backend'] ?? false;
        $this->index = $config['index'] ?? null;
        $this->request = $config['request'] ?? [];
        $this->options = $config;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getRoutePrefix(): string
    {
        return $this->routePrefix;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getIndex()
    {
        if ($this->index) {
            return $this->index;
        }

        return $this->request['_environment'] ?? $this->name;
    }

    public function matchRequest(Request $request)
    {
        if (null === $this->regex) {
            return true;
        }

        if (\strlen($this->baseUrl) > 0) {
            $url = \vsprintf('%s://%s%s', [$request->getScheme(), $request->getHttpHost(), $request->getPathInfo()]);
        } else {
            $url = \vsprintf('%s://%s%s', [$request->getScheme(), $request->getHttpHost(), $request->getBasePath()]);
        }

        return 1 === \preg_match($this->regex, $url) ? true : false;
    }

    public function modifyRequest(Request $request)
    {
        // backward compatibility
        $request->attributes->set('_environment', $this->getIndex());
        $request->attributes->set('_backend', $this->backend);

        if (!empty($this->request)) {
            foreach ($this->request as $key => $value) {
                $request->attributes->set($key, $value);

                if ('_locale' === $key) {
                    $request->setLocale($value);
                }
            }
        }
    }

    /**
     * @param mixed|null $default
     *
     * @return mixed|null
     */
    public function getOption(string $propertyPath, $default = null)
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        if (!$propertyAccessor->isReadable($this->options, $propertyPath)) {
            return $default;
        }

        return $propertyAccessor->getValue($this->options, $propertyPath);
    }

    public function hasOption(string $option): bool
    {
        return isset($this->options[$option]) && null != $this->options[$option];
    }
}
