<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Controller;

use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequest;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequestManager;
use EMS\CommonBundle\Twig\AssetRuntime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AssetController extends AbstractController
{
    private ClientRequest $clientRequestManager;
    private string $projectDir;
    private AssetRuntime $assetRuntime;
    private RequestStack $requestStack;

    public function __construct(ClientRequestManager $clientRequestManager, AssetRuntime $assetRuntime, RequestStack $requestStack, string $projectDir)
    {
        $this->clientRequestManager = $clientRequestManager->getDefault();
        $this->assetRuntime = $assetRuntime;
        $this->requestStack = $requestStack;
        $this->projectDir = $projectDir;
    }

    public function proxyToCacheKey(string $requestPath, string $environment = null): Response
    {
        $cacheKey = $this->clientRequestManager->getCacheKey('', $environment);
        $target = \implode(DIRECTORY_SEPARATOR, [
            'bundles',
            $cacheKey,
        ]);

        return $this->proxy($requestPath, $target);
    }

    public function proxyToZipArchive(string $requestPath, string $hash): Response
    {
        $saveDir = \implode(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            'public',
            'bundles',
            $hash,
        ]);
        $this->assetRuntime->unzip($hash, $saveDir);

        $target = \implode(DIRECTORY_SEPARATOR, [
            'bundles',
            $hash,
        ]);

        return $this->proxy($requestPath, $target);
    }

    public function proxyFromRefererPattern(string $requestPath, string $pathRegex = '/^\\/channel\\/(?P<environment>([a-z\\-0-9_]+))(\\/)?/', string $targetPattern = 'bundles'.DIRECTORY_SEPARATOR.'%cache_key%'): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \RuntimeException('Unexpected null request');
        }

        $referer = $request->headers->get('Referer');
        if (!\is_string($referer)) {
            throw new NotFoundHttpException('Referer not found');
        }

        $referer = \parse_url($referer);
        if (false === $referer) {
            throw new \RuntimeException('Unexpected unparsable referer');
        }

        if ($request->getHost() !== ($referer['host'] ?? null)) {
            throw new BadRequestHttpException('Host and referer\'s host does not match');
        }

        $path = \substr($referer['path'] ?? '', \strlen($request->getBasePath()));
        $matches = [];
        if (!\preg_match($pathRegex, $path, $matches) && isset($referer['query'])) {
            return $this->proxyToZipArchive($requestPath, $referer['query']);
        }

        $environment = $matches['environment'] ?? null;
        if (!\is_string($environment)) {
            throw new NotFoundHttpException('Environment not found');
        }

        return $this->proxyToCacheKey($requestPath, $environment);
    }

    public function proxy(string $requestPath, string $target): Response
    {
        $file = \implode(DIRECTORY_SEPARATOR, [
            $this->projectDir,
            'public',
            $target,
            $requestPath,
        ]);

        if (!\file_exists($file)) {
            throw new NotFoundHttpException(\sprintf('File %s not found', $file));
        }
        $response = new BinaryFileResponse($file);
        $this->fixGuessedMimeType($response, $file);
        $response->headers->set('X-Proxy-Target-Base-Url', $target);

        return $response;
    }

    private function fixGuessedMimeType(BinaryFileResponse $response, string $file): void
    {
        $exploded = \explode('.', $file);
        $extension = \end($exploded);

        switch ($extension) {
            case 'css':
                $response->headers->set('Content-Type', 'text/css');
                break;
        }
    }
}
