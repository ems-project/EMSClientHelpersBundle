<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Twig;

use EMS\ClientHelperBundle\Helper\Routing\Url\Transformer;
use Twig\Extension\RuntimeExtensionInterface;

final class RoutingRuntime implements RuntimeExtensionInterface
{
    private Transformer $transformer;

    public function __construct(Transformer $transformer)
    {
        $this->transformer = $transformer;
    }

    /**
     * @param array<mixed> $parameters
     */
    public function createUrl(string $relativePath, string $path, array $parameters = []): string
    {
        $url = $this->transformer->getGenerator()->createUrl($relativePath, $path);

        if ($parameters) {
            $url .= '?'.\http_build_query($parameters);
        }

        return $url;
    }

    /**
     * @return mixed
     */
    public function transform(string $content, ?string $locale = null, ?string $baseUrl = null)
    {
        return $this->transformer->transform($content, $locale, $baseUrl);
    }
}
