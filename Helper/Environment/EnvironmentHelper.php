<?php

namespace EMS\ClientHelperBundle\Helper\Environment;

use EMS\ClientHelperBundle\Exception\EnvironmentNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;

class EnvironmentHelper
{
    /** @var Environment[] */
    private $environments = [];
    /** @var RequestStack */
    private $requestStack;
    /** @var string */
    private $emschEnv;

    /**
     * @param array<string, array> $environments
     */
    public function __construct(RequestStack $requestStack, string $emschEnv, array $environments)
    {
        $this->requestStack = $requestStack;
        $this->emschEnv = $emschEnv;

        foreach ($environments as $name => $config) {
            $this->environments[] = new Environment($name, $config);
        }
    }

    public function addEnvironment(Environment $environment): void
    {
        $this->environments[] = $environment;
    }

    /**
     * @return Environment[]
     */
    public function getEnvironments(): array
    {
        return $this->environments;
    }

    public function getBackend(): ?string
    {
        $current = $this->requestStack->getCurrentRequest();

        return $current ? $current->get('_backend') : null;
    }

    /**
     * Important for twig loader on kernel terminate we don't have a current request.
     * So this function remembers it's environment and can still return it.
     */
    public function getEnvironmentName(): ?string
    {
        static $env = null;

        if (null === $env) {
            $current = $this->requestStack->getCurrentRequest();

            if ($current) {
                $env = $current->get('_environment', null);
            } elseif ('cli' === PHP_SAPI) {
                $env = $this->emschEnv;
            }
        }

        return $env;
    }

    public function getLocale(): string
    {
        $current = $this->requestStack->getCurrentRequest();
        if (null === $current) {
            throw new \RuntimeException('Unexpected null request');
        }

        return $current->getLocale();
    }

    public function getEnvironment(): Environment
    {
        $current = $this->requestStack->getCurrentRequest();
        if (null === $current) {
            throw new EnvironmentNotFoundException();
        }
        $environmentName = $current->get('_environment', null);
        if (null === $environmentName) {
            throw new EnvironmentNotFoundException();
        }

        foreach ($this->environments as $environment) {
            if ($environmentName === $environment->getName()) {
                return $environment;
            }
        }

        throw new EnvironmentNotFoundException();
    }
}
