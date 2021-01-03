<?php

namespace EMS\ClientHelperBundle\Helper\Elasticsearch;

use Elastica\Aggregation\Max;
use Elastica\Aggregation\Terms;
use Elastica\Exception\ResponseException;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\ResultSet;
use EMS\ClientHelperBundle\Exception\EnvironmentNotFoundException;
use EMS\ClientHelperBundle\Exception\SingleResultException;
use EMS\ClientHelperBundle\Helper\Environment\Environment;
use EMS\ClientHelperBundle\Helper\Environment\EnvironmentHelper;
use EMS\CommonBundle\Common\EMSLink;
use EMS\CommonBundle\Elasticsearch\Document\EMSSource;
use EMS\CommonBundle\Elasticsearch\Exception\NotFoundException;
use EMS\CommonBundle\Helper\EmsFields;
use EMS\CommonBundle\Search\Search;
use EMS\CommonBundle\Service\ElasticaService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ClientRequest
{
    /** @var int */
    private const CONTENT_TYPE_LIMIT = 500;
    /** @var EnvironmentHelper */
    private $environmentHelper;
    /** @var string */
    private $indexPrefix;
    /** @var LoggerInterface */
    private $logger;
    /** @var AdapterInterface */
    private $cache;
    /** @var array */
    private $options;
    /** @var array<string, \DateTime> */
    private $lastUpdateByType;

    /**
     * @var string
     */
    private $name;

    const OPTION_INDEX_PREFIX = 'index_prefix';

    private ElasticaService $elasticaService;

    /**
     * @param string $name
     */
    public function __construct(
        ElasticaService $elasticaService,
        EnvironmentHelper $environmentHelper,
        LoggerInterface $logger,
        AdapterInterface $cache,
        $name,
        array $options = []
    ) {
        $this->environmentHelper = $environmentHelper;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->options = $options;
        $this->elasticaService = $elasticaService;
        $this->lastUpdateByType = [];
        $this->indexPrefix = isset($options[self::OPTION_INDEX_PREFIX]) ? $options[self::OPTION_INDEX_PREFIX] : null;
        $this->name = $name;
    }

    /**
     * @param string $analyzer
     *
     * @return array
     */
    public function analyze(string $text, $analyzer)
    {
        if (empty($text)) {
            return [];
        }

        $this->logger->debug('ClientRequest : analyze {text} with {analyzer}', ['text' => $text, 'analyzer' => $analyzer]);
        $out = [];
        \preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', $text, $out);
        $words = $out[0];
        $index = $this->getFirstIndex();

        return $this->elasticaService->filterStopWords($index, $analyzer, $words);
    }

    /**
     * @param string $type
     * @param string $id
     *
     * @return array
     *
     * @throws SingleResultException
     */
    public function get($type, $id)
    {
        $this->logger->debug('ClientRequest : get {type}:{id}', ['type' => $type, 'id' => $id]);

        return $this->searchOne($type, [
            'query' => [
                'term' => [
                    '_id' => $id,
                ],
            ],
        ]);
    }

    public function getAllChildren(string $emsKey, string $childrenField): array
    {
        $this->logger->debug('ClientRequest : getAllChildren for {emsKey}', ['emsKey' => $emsKey]);
        $out = [$emsKey];
        $item = $this->getByEmsKey($emsKey);

        if (false === $item) {
            return $out;
        }

        if (isset($item['_source'][$childrenField]) && \is_array($item['_source'][$childrenField])) {
            foreach ($item['_source'][$childrenField] as $key) {
                $out = \array_merge($out, $this->getAllChildren($key, $childrenField));
            }
        }

        return $out;
    }

    /**
     * @param string $emsLink
     *
     * @return array<string, mixed>|false
     */
    public function getByEmsKey($emsLink, array $sourceFields = [])
    {
        return $this->getByOuuid(static::getType($emsLink), static::getOuuid($emsLink), $sourceFields);
    }

    /**
     * @param string $type
     * @param string $ouuid
     *
     * @return array<string, mixed>|false
     */
    public function getByOuuid($type, $ouuid, array $sourceFields = [], array $source_exclude = [])
    {
        $this->logger->debug('ClientRequest : getByOuuid {type}:{id}', ['type' => $type, 'id' => $ouuid]);
        if (!empty($source_exclude)) {
            @\trigger_error('_source_exclude field are not supported anymore', E_USER_DEPRECATED);
        }

        foreach ($this->getIndex() as $index) {
            try {
                $document = $this->elasticaService->getDocument($index, $type, $ouuid, $sourceFields);

                return $document->getRaw();
            } catch (NotFoundException $e) {
            }
        }

        return false;
    }

    /**
     * @param string[] $ouuids
     *
     * @return array<mixed>
     */
    public function getByOuuids(string $type, array $ouuids): array
    {
        $this->logger->debug('ClientRequest : getByOuuids {type}:{id}', ['type' => $type, 'id' => $ouuids]);

        $query = $this->elasticaService->getTermsQuery('_id', $ouuids);
        $query = $this->elasticaService->filterByContentTypes($query, [$type]);
        $search = new Search($this->getIndex(), $query);

        return $this->elasticaService->search($search)->getResponse()->getData();
    }

    /**
     * @return string[]
     */
    public function getContentTypes(): array
    {
        $index = $this->getIndex();
        $search = new Search($index);
        $search->setSize(0);
        $terms = new Terms(EMSSource::FIELD_CONTENT_TYPE);
        $terms->setField(EMSSource::FIELD_CONTENT_TYPE);
        $terms->setSize(self::CONTENT_TYPE_LIMIT);
        $search->addAggregation($terms);
        $resultSet = $this->elasticaService->search($search);
        $aggregation = $resultSet->getAggregation(EMSSource::FIELD_CONTENT_TYPE);
        $contentTypes = [];
        foreach ($aggregation['buckets'] ?? [] as $bucket) {
            $contentTypes[] = $bucket['key'];
        }
        if (\count($contentTypes) >= self::CONTENT_TYPE_LIMIT) {
            $this->logger->warning('The get content type function is only considering the first {limit} content type', ['limit' => self::CONTENT_TYPE_LIMIT]);
        }

        return $contentTypes;
    }

    /**
     * @param string $field
     *
     * @return string
     */
    public function getFieldAnalyzer($field)
    {
        $this->logger->debug('ClientRequest : getFieldAnalyzer {field}', ['field' => $field]);

        return $this->elasticaService->getFieldAnalyzer($this->getFirstIndex(), $field);
    }

    public function getHierarchy(string $emsKey, string $childrenField, int $depth = null, array $sourceFields = [], EMSLink $activeChild = null): ?HierarchicalStructure
    {
        $this->logger->debug('ClientRequest : getHierarchy for {emsKey}', ['emsKey' => $emsKey]);
        $item = $this->getByEmsKey($emsKey, $sourceFields);

        if (false === $item) {
            return null;
        }
        $contentType = $item['_source'][EMSSource::FIELD_CONTENT_TYPE] ?? $item['_type'];
        $out = new HierarchicalStructure($contentType, $item['_id'], $item['_source'], $activeChild);

        if (null === $depth || $depth) {
            if (isset($item['_source'][$childrenField]) && \is_array($item['_source'][$childrenField])) {
                foreach ($item['_source'][$childrenField] as $key) {
                    if ($key) {
                        $child = $this->getHierarchy($key, $childrenField, (null === $depth ? null : $depth - 1), $sourceFields, $activeChild);
                        if ($child) {
                            $out->addChild($child);
                        }
                    }
                }
            }
        }

        return $out;
    }

    public function mustBeBind(): bool
    {
        return $this->options['must_be_bind'] ?? true;
    }

    public function isBind(): bool
    {
        return \count($this->getEnvironments()) > 0 && null !== $this->environmentHelper->getEnvironmentName();
    }

    public function getEnvironments(): array
    {
        $environments = [];
        /** @var Environment $environment */
        foreach ($this->environmentHelper->getEnvironments() as $environment) {
            $environments[] = $environment->getIndex();
        }

        return $environments;
    }

    public function getCurrentEnvironment(): Environment
    {
        return $this->environmentHelper->getEnvironment();
    }

    public function getLastChangeDate(string $type): \DateTime
    {
        if (empty($this->lastUpdateByType)) {
            $boolQuery = new BoolQuery();
            $operationQuery = $this->elasticaService->getTermsQuery(EmsFields::LOG_OPERATION_FIELD, [
                EmsFields::LOG_OPERATION_UPDATE,
                EmsFields::LOG_OPERATION_DELETE,
                EmsFields::LOG_OPERATION_CREATE,
            ]);
            $boolQuery->addMust($operationQuery);

            $environmentQuery = $this->elasticaService->getTermsQuery(EmsFields::LOG_ENVIRONMENT_FIELD, $this->getEnvironments());
            $boolQuery->addMust($environmentQuery);

            $instanceQuery = $this->elasticaService->getTermsQuery(EmsFields::LOG_INSTANCE_ID_FIELD, $this->getPrefixes());
            $boolQuery->addMust($instanceQuery);

            $search = new Search([EmsFields::LOG_ALIAS], $boolQuery);
            $search->setSize(0);

            $maxUpdate = new Max('maxUpdate');
            $maxUpdate->setField(EmsFields::LOG_DATETIME_FIELD);
            $lastUpdate = new Terms('lastUpdate');
            $lastUpdate->setField(EmsFields::LOG_CONTENTTYPE_FIELD);
            $lastUpdate->setSize(100);
            $lastUpdate->addAggregation($maxUpdate);
            $search->addAggregation($lastUpdate);

            try {
                $resultSet = $this->elasticaService->search($search);
                $lastUpdateAggregation = $resultSet->getAggregation('lastUpdate');

                foreach ($lastUpdateAggregation['buckets'] as $maxDate) {
                    $this->lastUpdateByType[$maxDate['key']] = new \DateTime($maxDate['maxUpdate']['value_as_string']);
                }
            } catch (ResponseException $e) {
                $this->logger->warning('log.ems_log_alias_not_found', [
                    'alias' => EmsFields::LOG_ALIAS,
                ]);
            }
        }

        if (!empty($this->lastUpdateByType)) {
            $mostRecentUpdate = new \DateTime('2019-06-01T12:00:00Z');
            $types = \explode(',', $type);
            foreach ($types as $currentType) {
                if (isset($this->lastUpdateByType[$currentType]) && $mostRecentUpdate < $this->lastUpdateByType[$currentType]) {
                    $mostRecentUpdate = $this->lastUpdateByType[$currentType];
                }
            }
            $this->logger->info('log.last_update_date', [
                'contenttypes' => $type,
                'lastupdate' => $mostRecentUpdate->format('c'),
            ]);

            return $mostRecentUpdate;
        }

        $this->logger->warning('log.ems_log_not_found', [
            'alias' => EmsFields::LOG_ALIAS,
            'type' => EmsFields::LOG_TYPE,
            'types' => $type,
            'environments' => $this->getEnvironments(),
            'instance_ids' => $this->getPrefixes(),
        ]);

        $result = $this->search($type, [
            'sort' => ['_published_datetime' => ['order' => 'desc', 'missing' => '_last']],
            '_source' => '_published_datetime',
        ], 0, 1);

        if ($result['hits']['total'] > 0 && isset($result['hits']['hits']['0']['_source']['_published_datetime'])) {
            return new \DateTime($result['hits']['hits']['0']['_source']['_published_datetime']);
        }

        return new \DateTime('Wed, 09 Feb 1977 16:00:00 GMT');
    }

    /**
     * @return string
     */
    public function getLocale()
    {
        return $this->environmentHelper->getLocale();
    }

    /**
     * @param string $emsLink
     *
     * @return string|null
     */
    public static function getOuuid($emsLink)
    {
        if (!\strpos($emsLink, ':')) {
            return $emsLink;
        }

        $split = \preg_split('/:/', $emsLink);

        return \array_pop($split);
    }

    public function hasOption(string $option): bool
    {
        return isset($this->options[$option]) && null != $this->options[$option];
    }

    /**
     * @param string $propertyPath
     * @param string $default
     *
     * @return mixed|null
     */
    public function getOption($propertyPath, $default = null)
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        if (!$propertyAccessor->isReadable($this->options, $propertyPath)) {
            return $default;
        }

        return $propertyAccessor->getValue($this->options, $propertyPath);
    }

    /**
     * @return array
     */
    public function getPrefixes()
    {
        return \explode('|', $this->indexPrefix);
    }

    /**
     * @param string $emsLink
     *
     * @return string|null
     */
    public static function getType($emsLink)
    {
        if (!\strpos($emsLink, ':')) {
            return $emsLink;
        }

        $split = \preg_split('/:/', $emsLink);

        return $split[0];
    }

    /**
     * @param string|array|null $type
     * @param int               $from
     * @param int               $size
     *
     * @return array
     */
    public function search($type, array $body, $from = 0, $size = 10, array $sourceExclude = [], ?string $regex = null)
    {
        if (null === $type) {
            $types = [];
        } elseif (\is_array($type)) {
            $types = $type;
        } else {
            $types = \explode(',', $type);
        }

        if (null === $regex) {
            $index = $this->getIndex();
        } else {
            $index = [];
            foreach ($this->getIndex() as $alias) {
                if (\preg_match(\sprintf('/%s/', $regex), $alias)) {
                    $index[] = $alias;
                }
            }
            $query = null;
            if (\count($types) > 0) {
                $query = $this->elasticaService->filterByContentTypes(null, $types);
            }
            $search = new Search($index, $query);
            $search->setSize(0);
            $terms = new Terms('indexes');
            $terms->setField('_index');
            $search->addAggregation($terms);
            $resultSet = $this->elasticaService->search($search);

            foreach ($resultSet->getAggregation('indexes')['buckets'] as $bucket) {
                if (\preg_match(\sprintf('/%s/', $regex), $bucket['key'])) {
                    $index[] = $bucket['key'];
                }
            }
        }

        $arguments = [
            'index' => $index,
            'type' => $type,
            'body' => $body,
            'size' => $body['size'] ?? $size,
            'from' => $body['from'] ?? $from,
        ];

        if (!empty($sourceExclude)) {
            @\trigger_error('_source_exclude field are not supported anymore', E_USER_DEPRECATED);
        }

        $this->logger->debug('ClientRequest : search for {type}', $arguments);
        $search = $this->elasticaService->convertElasticsearchSearch($arguments);
        $resultSet = $this->elasticaService->search($search);

        return $resultSet->getResponse()->getData();
    }

    /**
     * @param string[] $types
     */
    public function initializeCommonSearch(array $types, ?AbstractQuery $query = null): Search
    {
        $query = $this->elasticaService->filterByContentTypes($query, $types);

        return new Search($this->getIndex(), $query);
    }

    public function commonSearch(Search $search): ResultSet
    {
        return $this->elasticaService->search($search);
    }

    /**
     * @return array
     *
     * @throws EnvironmentNotFoundException
     */
    public function searchArgs(array $arguments)
    {
        if (!isset($arguments['index'])) {
            $arguments['index'] = $this->getIndex();
        }
        $search = $this->elasticaService->convertElasticsearchSearch($arguments);

        return $this->elasticaService->search($search)->getResponse()->getData();
    }

    /**
     * @param string $type
     * @param array  $parameters
     * @param int    $from
     * @param int    $size
     *
     * @return array
     */
    public function searchBy($type, $parameters, $from = 0, $size = 10)
    {
        $this->logger->debug('ClientRequest : searchBy for type {type}', ['type' => $type]);
        $body = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
        ];

        foreach ($parameters as $id => $value) {
            $body['query']['bool']['must'][] = [
                'term' => [
                    $id => [
                        'value' => $value,
                    ],
                ],
            ];
        }

        $search = $this->elasticaService->convertElasticsearchSearch([
            'index' => $this->getIndex(),
            'type' => $type,
            'body' => $body,
            'size' => $size,
            'from' => $from,
        ]);

        return $this->elasticaService->search($search)->getResponse()->getData();
    }

    /**
     * @param string|array $type
     *
     * @return array{_id: string, _type?: string, _source: array}
     *
     * @throws SingleResultException
     */
    public function searchOne($type, array $body, ?string $indexRegex = null): array
    {
        $this->logger->debug('ClientRequest : searchOne for {type}', ['type' => $type, 'body' => $body, 'indexRegex' => $indexRegex]);
        $search = $this->search($type, $body, 0, 2, [], $indexRegex);

        $hits = $search['hits'];

        if (1 !== \count($hits['hits'])) {
            throw new SingleResultException(\sprintf('expected 1 result, got %d', $hits['hits']));
        }

        return $hits['hits'][0];
    }

    /**
     * @return array{_id:string,_type:string,_source:array<mixed>}|null
     */
    public function searchOneBy(string $type, array $parameters): ?array
    {
        $this->logger->debug('ClientRequest : searchOneBy for type {type}', ['type' => $type]);

        $result = $this->searchBy($type, $parameters, 0, 1);

        if (1 == $result['hits']['total']) {
            return $result['hits']['hits'][0];
        }

        return null;
    }

    /**
     * @param string $type
     * @param array  $filter
     * @param int    $size
     * @param string $scrollId
     *
     * @return array
     */
    public function scroll($type, $filter = [], $size = 10, $scrollId = null)
    {
        $scrollTimeout = '30s';

        if ($scrollId) {
            return $this->elasticaService->nextScroll($scrollId, $scrollTimeout)->getData();
        }

        $search = $this->elasticaService->convertElasticsearchSearch([
            'index' => $this->getIndex(),
            'type' => $type,
            '_source' => $filter,
            'size' => $size,
        ]);

        return $this->elasticaService->scrollById($search, $scrollTimeout)->getResponse()->getData();
    }

    /**
     * @param string $timeout
     *
     * @return \Generator
     *
     * @throws EnvironmentNotFoundException
     */
    public function scrollAll(array $params, $timeout = '30s'): iterable
    {
        $params['index'] = $this->getIndex();
        $search = $this->elasticaService->convertElasticsearchSearch($params);
        $scroll = $this->elasticaService->scroll($search, $timeout);

        foreach ($scroll as $resultSet) {
            foreach ($resultSet as $result) {
                if (false === $result) {
                    continue;
                }
                yield $result->getHit();
            }
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @throws EnvironmentNotFoundException
     *
     * @todo rename to getEnvironmentAlias?
     */
    public function getCacheKey(string $prefix = '', string $environment = null): string
    {
        $index = $this->getIndex($environment);

        return $prefix.\implode('_', $index);
    }

    /**
     * @return string[]
     */
    private function getIndex(string $environment = null): array
    {
        if (null === $environment) {
            $environment = $this->environmentHelper->getEnvironmentName();
        }

        if (null === $environment) {
            throw new EnvironmentNotFoundException();
        }

        $prefixes = \explode('|', $this->indexPrefix);
        $out = [];
        foreach ($prefixes as $prefix) {
            $out[] = $prefix.$environment;
        }
        if (!empty($out)) {
            return $out;
        }

        return [$this->indexPrefix.$environment];
    }

    /**
     * @return string
     */
    private function getFirstIndex()
    {
        $aliases = $this->getIndex();
        if (\count($aliases) <= 0) {
            throw new \RuntimeException('Unexpected missing alias');
        }

        return $this->elasticaService->getIndexFromAlias(\reset($aliases));
    }

    public function getCacheResponse(array $cacheKey, ?string $type, callable $function)
    {
        if (null === $type) {
            return $function();
        }
        $cacheHash = \sha1(\json_encode($cacheKey));

        $cachedHierarchy = $this->cache->getItem($cacheHash);
        $lastUpdate = $this->getLastChangeDate($type);

        /** @var Response $response */
        $response = $cachedHierarchy->get();
        if (!$cachedHierarchy->isHit() || $response->getLastModified() != $lastUpdate) {
            $response = $function();
            $response->setLastModified($lastUpdate);
            $this->cache->save($cachedHierarchy->set($response));
            $this->logger->notice('log.cache_missed', [
                'cache_key' => $cacheHash,
                'type' => $type,
            ]);
        } else {
            $this->logger->notice('log.cache_hit', [
                'cache_key' => $cacheHash,
                'type' => $type,
            ]);
            $response = $cachedHierarchy->get();
        }

        return $response;
    }
}
