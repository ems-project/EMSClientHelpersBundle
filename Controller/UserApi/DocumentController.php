<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Controller\UserApi;

use EMS\ClientHelperBundle\Service\UserApi\DocumentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DocumentController
{
    /** @var DocumentService */
    private $documentService;

    public function __construct(DocumentService $service)
    {
        $this->documentService = $service;
    }

    public function show(string $contentType, string $ouuid, Request $request): JsonResponse
    {
        return new JsonResponse($this->documentService->getDocument($contentType, $ouuid, $request));
    }

    public function store(string $contentType, Request $request): JsonResponse
    {
        return new JsonResponse($this->documentService->storeDocument($contentType, $request));
    }

    public function update(string $contentType, string $ouuid, Request $request): JsonResponse
    {
        return new JsonResponse($this->documentService->updateDocument($contentType, $ouuid, $request));
    }

    public function merge(string $contentType, string $ouuid, Request $request): JsonResponse
    {
        return new JsonResponse($this->documentService->mergeDocument($contentType, $ouuid, $request));
    }
}