<?php

namespace EMS\ClientHelperBundle\Helper\Elasticsearch;

use EMS\ClientHelperBundle\Exception\EnvironmentNotFoundException;
use EMS\ClientHelperBundle\Helper\Search\Search;
use EMS\CommonBundle\Common\Document;
use EMS\CommonBundle\Common\EMSLink;
use Psr\Log\LoggerInterface;
use Twig\Extension\RuntimeExtensionInterface;

class ClientRequestRuntime implements RuntimeExtensionInterface
{
    /**
     * @var ClientRequestManager
     */
    private $manager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /** @var Document[] */
    private $documents = [];

    public function __construct(ClientRequestManager $manager, LoggerInterface $logger)
    {
        $this->manager = $manager;
        $this->logger = $logger;
        $this->documents = [];
    }

    /**
     * @param string $type
     * @param int    $from
     * @param int    $size
     *
     * @return array
     */
    public function search($type, array $body, $from = 0, $size = 10, array $sourceExclude = [], ?string $regex = null)
    {
        $client = $this->manager->getDefault();

        return $client->search($type, $body, $from, $size, $sourceExclude, $regex);
    }

    public function searchConfig(): Search
    {
        return new Search($this->manager->getDefault());
    }

    /**
     * @return array|false|null false when multiple results
     *
     * @throws EnvironmentNotFoundException
     */
    public function data(string $input)
    {
        @\trigger_error(\sprintf('The filter emsch_data is deprecated and should not be used anymore. use the filter emsch_get instead"'), E_USER_DEPRECATED);

        $emsLink = EMSLink::fromText($input);
        $body = [
            'query' => [
                'bool' => [
                    'must' => [['term' => ['_id' => $emsLink->getOuuid()]]],
                ],
            ],
        ];

        if ($emsLink->hasContentType()) {
            $body['query']['bool']['should'] = [
                ['term' => ['_type' => $emsLink->getContentType()]],
                ['term' => ['_contenttype' => $emsLink->getContentType()]],
                ['term' => ['contenttype' => $emsLink->getContentType()]],
            ];
        }

        $result = $this->manager->getDefault()->searchArgs(['body' => $body]);
        $total = $result['hits']['total'];

        if (1 === $total) {
            return $result['hits']['hits'];
        }

        return ($total > 1) ? false : null;
    }

    /**
     * @throws EnvironmentNotFoundException
     */
    public function get(string $input): ?Document
    {
        $emsLink = EMSLink::fromText($input);

        if (isset($this->documents[$emsLink->__toString()])) {
            return $this->documents[$emsLink->__toString()];
        }

        $bool = ['must' => [['term' => ['_id' => $emsLink->getOuuid()]]]];

        if ($emsLink->hasContentType()) {
            $bool['minimum_should_match'] = 1;
            $bool['should'] = [
                ['term' => ['_type' => $emsLink->getContentType()]],
                ['term' => ['_contenttype' => $emsLink->getContentType()]],
            ];
        }

        $result = $this->manager->getDefault()->searchArgs(['body' => ['query' => ['bool' => $bool]]]);
        $total = $result['hits']['total']['value'] ?? $result['hits']['total'];

        if (0 === $total) {
            return null;
        }

        if (1 !== $total) {
            $this->logger->error(\sprintf('emsch_get filter found %d results for the ems key %s', $total, $input));
        }

        $document = new Document($emsLink->getContentType(), $emsLink->getOuuid(), $result['hits']['hits'][0]['_source']);
        $this->documents[$emsLink->__toString()] = $document;

        return $document;
    }
}
