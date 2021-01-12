<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Controller;

use EMS\ClientHelperBundle\Helper\Api\ApiService;
use EMS\ClientHelperBundle\Helper\Hashcash\HashcashHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController
{
    private ApiService $service;
    private HashcashHelper $hashcashHelper;

    public function __construct(ApiService $service, HashcashHelper $hashcashHelper)
    {
        $this->service = $service;
        $this->hashcashHelper = $hashcashHelper;
    }

    public function contentTypes(string $apiName): JsonResponse
    {
        return $this->service->getContentTypes($apiName)->getResponse();
    }

    public function contentType(Request $request, string $apiName, string $contentType): JsonResponse
    {
        $scrollId = $request->query->get('scroll');
        $size = $request->query->get('size');
        $filter = $request->query->get('filter', []);

        return $this->service->getContentType($apiName, $contentType, $filter, $size, $scrollId)->getResponse();
    }

    public function document(string $apiName, string $contentType, string $ouuid): JsonResponse
    {
        return $this->service->getDocument($apiName, $contentType, $ouuid)->getResponse();
    }

    public function handleFormPostRequest(Request $request, string $apiName, string $contentType, ?string $ouuid, string $csrfId, string $validationTemplate, int $hashcashLevel, string $hashAlgo, bool $forceCreate = false): JsonResponse
    {
        $this->hashcashHelper->validateHashcash($request, $csrfId, $hashcashLevel, $hashAlgo);
        $data = $this->service->treatFormRequest($request, $apiName, $validationTemplate);

        if (null === $data) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Empty data',
            ]);
        }

        try {
            if (null === $ouuid || $forceCreate) {
                $ouuid = $this->service->createDocument($apiName, $contentType, $ouuid, $data);
            } else {
                $ouuid = $this->service->updateDocument($apiName, $contentType, $ouuid, $data);
            }

            return new JsonResponse([
                'success' => true,
                'ouuid' => $ouuid,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function createDocumentFromForm(Request $request, string $apiName, string $contentType, ?string $ouuid, string $redirectUrl, string $validationTemplate = null): RedirectResponse
    {
        $body = $this->service->treatFormRequest($request, $apiName, $validationTemplate);
        $ouuid = $this->service->createDocument($apiName, $contentType, $ouuid, $body);

        $url = \str_replace('%ouuid%', $ouuid, $redirectUrl);
        $url = \str_replace('%contenttype%', $contentType, $url);

        return new RedirectResponse($url);
    }

    public function updateDocumentFromForm(Request $request, string $apiName, string $contentType, string $ouuid, string $redirectUrl, string $validationTemplate = null): RedirectResponse
    {
        $body = $this->service->treatFormRequest($request, $apiName, $validationTemplate);
        $ouuid = $this->service->updateDocument($apiName, $contentType, $ouuid, $body);

        $url = \str_replace('%ouuid%', $ouuid, $redirectUrl);
        $url = \str_replace('%contenttype%', $contentType, $url);

        return new RedirectResponse($url);
    }
}
