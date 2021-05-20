<?php

declare(strict_types=1);

namespace EMS\ClientHelperBundle\Helper\Search;

use Elastica\Aggregation\Nested;
use Elastica\Aggregation\Terms as TermsAggregation;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\Exists;
use Elastica\Query\Range;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequest;
use EMS\ClientHelperBundle\Helper\Request\RequestHelper;
use Symfony\Component\HttpFoundation\Request;

final class Filter
{
    private ClientRequest $clientRequest;
    private string $name;
    private string $type;
    private string $field;
    private ?string $secondaryField = null;
    private ?string $nestedPath = null;

    private ?string $sortField = null;
    private string $sortOrder;
    private bool $reversedNested = false;

    private ?int $aggSize = null;
    /** default true for terms, when value passed default false */
    private bool $postFilter = true;
    /** only public filters will handle a request. */
    private bool $public = false;
    /** if not all doc contain the filter */
    private bool $optional = false;
    private ?AbstractQuery $queryFilters = null;
    /** @var string[] */
    private array $queryTypes = [];

    private ?AbstractQuery $query = null;

    /** @var mixed|null */
    private $value;
    /** @var array<mixed> */
    private array $choices = [];
    /** @var bool|string */
    private $dateFormat;

    private const TYPE_TERM = 'term';
    private const TYPE_TERMS = 'terms';
    private const TYPE_DATE_RANGE = 'date_range';
    private const TYPE_DATE_VERSION = 'date_version';

    private const TYPES = [
        self::TYPE_TERM,
        self::TYPE_TERMS,
        self::TYPE_DATE_RANGE,
        self::TYPE_DATE_VERSION,
    ];

    /**
     * @param array<mixed> $options
     */
    public function __construct(ClientRequest $clientRequest, string $name, array $options)
    {
        $this->clientRequest = $clientRequest;

        if (!\in_array($options['type'], self::TYPES)) {
            throw new \Exception(\sprintf('invalid filter type %s', $options['type']));
        }

        $this->name = $name;
        $this->type = $options['type'];
        $this->field = $options['field'] ?? $name;
        $this->secondaryField = $options['secondary_field'] ?? null;
        $this->nestedPath = $options['nested_path'] ?? null;

        $this->public = isset($options['public']) ? (bool) $options['public'] : true;
        $this->optional = isset($options['optional']) ? (bool) $options['optional'] : false;
        $this->aggSize = isset($options['aggs_size']) ? (int) $options['aggs_size'] : null;
        $this->sortField = isset($options['sort_field']) ? $options['sort_field'] : null;
        $this->sortOrder = isset($options['sort_order']) ? $options['sort_order'] : 'asc';
        $this->reversedNested = isset($options['reversed_nested']) ? $options['reversed_nested'] : false;
        $this->dateFormat = isset($options['date_format']) ? $options['date_format'] : 'd-m-Y H:i:s';
        $this->setPostFilter($options);

        if (isset($options['value'])) {
            $this->setQuery($options['value']);
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSortField(): ?string
    {
        return $this->sortField;
    }

    public function getSortOrder(): string
    {
        return $this->sortOrder;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getField(): string
    {
        return $this->isNested() ? $this->nestedPath.'.'.$this->field : $this->field;
    }

    /**
     * @return mixed|null
     */
    public function getValue()
    {
        return $this->value;
    }

    public function hasAggSize(): bool
    {
        return null !== $this->aggSize;
    }

    public function getAggSize(): ?int
    {
        return $this->aggSize;
    }

    public function isActive(): bool
    {
        return !empty($this->query);
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function getQuery(): ?AbstractQuery
    {
        if ($this->optional && self::TYPE_DATE_VERSION !== $this->type && null !== $this->query) {
            return $this->getQueryOptional($this->getField(), $this->query);
        }

        return $this->query;
    }

    public function isPostFilter(): bool
    {
        return $this->postFilter;
    }

    public function handleRequest(Request $request): void
    {
        if (null !== $this->field) {
            $this->field = RequestHelper::replace($request, $this->field);
        }

        if (null !== $this->value) {
            if (\is_array($this->value)) {
                $this->value = \array_map(function ($v) use ($request) {
                    return \is_string($v) ? RequestHelper::replace($request, $v) : $v;
                }, $this->value);
            } elseif (\is_string($this->value)) {
                $this->value = RequestHelper::replace($request, $this->value);
            }
        }

        $requestValue = $request->get($this->name);

        if ($this->public && $requestValue) {
            $this->setQuery($requestValue);
        } elseif (null !== $this->value) {
            $this->setQuery($this->value);
        }
    }

    /**
     * @param array<mixed> $aggregation
     * @param string[]     $types
     */
    public function handleAggregation(array $aggregation, array $types = [], ?AbstractQuery $queryFilters = null): void
    {
        $this->queryTypes = $types;
        $this->queryFilters = $queryFilters;
        $this->setChoices();

        $data = $aggregation['nested'] ?? $aggregation;
        $buckets = $data['filtered_'.$this->name]['buckets'] ?? $data['buckets'];

        foreach ($buckets as $bucket) {
            if (!isset($this->choices[$bucket['key']])) {
                continue;
            }
            $this->choices[$bucket['key']]['filter'] = $bucket['doc_count'];

            if (!isset($bucket['reversed_nested'])) {
                continue;
            }

            $this->choices[$bucket['key']]['reversed_nested'] = $bucket['reversed_nested']['doc_count'];
        }
    }

    public function isChosen(string $choice): bool
    {
        if (!isset($this->choices[$choice])) {
            return false;
        }

        return $this->choices[$choice]['active'];
    }

    /**
     * @return array<mixed>
     */
    public function getChoices(): array
    {
        $this->setChoices();

        return $this->choices;
    }

    public function isNested(): bool
    {
        return null !== $this->nestedPath;
    }

    public function getNestedPath(): ?string
    {
        return $this->nestedPath;
    }

    public function isReversedNested(): bool
    {
        return $this->reversedNested;
    }

    /**
     * @param mixed $value
     */
    private function setQuery($value): void
    {
        switch ($this->type) {
            case self::TYPE_TERM:
                $this->value = $value;
                $term = new Term();
                $term->setTerm($this->getField(), $value);
                $this->query = $term;
                break;
            case self::TYPE_TERMS:
                $this->value = \is_array($value) ? $value : [$value];
                $term = new Terms($this->getField(), $value);
                $this->query = $term;
                break;
            case self::TYPE_DATE_RANGE:
                $this->value = \is_array($value) ? $value : [$value];
                $this->query = $this->getQueryDateRange($this->value);
                break;
            case self::TYPE_DATE_VERSION:
                $this->value = $value;
                $this->query = $this->getQueryVersion();
                break;
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function getQueryDateRange(array $value): ?AbstractQuery
    {
        if (!isset($value['start']) && !isset($value['end'])) {
            return null;
        }

        $start = $end = null;

        if (!empty($value['start'])) {
            $startDatetime = $this->createDateTimeForQuery($value['start'], ' 00:00:00');
            $start = $startDatetime ? $startDatetime->format('Y-m-d') : null;
        }
        if (!empty($value['end'])) {
            $endDatetime = $this->createDateTimeForQuery($value['end'], ' 23:59:59');
            $end = $endDatetime ? $endDatetime->format('Y-m-d') : null;
        }

        if (null === $start && null === $end) {
            return null;
        }

        return new Range($this->getField(), \array_filter(['gte' => $start, 'lte' => $end]));
    }

    private function getQueryVersion(): ?AbstractQuery
    {
        if (null === $this->value || !\is_string($this->value)) {
            return null;
        }

        if ('now' === $this->value) {
            $dateTime = new \DateTimeImmutable();
        } else {
            $format = \is_string($this->dateFormat) ? $this->dateFormat : \DATE_ATOM;
            $dateTime = \DateTimeImmutable::createFromFormat($format, $this->value);
        }

        if (!$dateTime instanceof \DateTimeImmutable) {
            return null;
        }

        $dateString = $dateTime->format('Y-m-d');

        $fromField = $this->field ?? 'version_from_date';
        $toField = $this->secondaryField ?? 'version_to_date';

        $boolQuery = new BoolQuery();
        $before = new Range($fromField, ['lte' => $dateString, 'format' => 'yyyy-MM-dd']);
        $after = new Range($toField, ['gt' => $dateString, 'format' => 'yyyy-MM-dd']);
        $boolQuery->addMust($before);
        $boolQuery->addMust($this->getQueryOptional($toField, $after));

        return $boolQuery;
    }

    private function createDateTimeForQuery(string $value, ?string $time = ''): ?\DateTime
    {
        if (false === $this->dateFormat) {
            return new \DateTime($value);
        }

        if (!\is_string($this->dateFormat)) {
            return null;
        }

        $dateTime = \DateTime::createFromFormat($this->dateFormat, \sprintf('%s %s', $value, $time));

        return $dateTime instanceof \DateTime ? $dateTime : null;
    }

    private function getQueryOptional(string $field, AbstractQuery $query): AbstractQuery
    {
        $boolQuery = new BoolQuery();
        $boolQuery->setMinimumShouldMatch(1);
        $orMustNotExists = new BoolQuery();
        $orMustNotExists->addMustNot(new Exists($field));
        $boolQuery->addShould($query);
        $boolQuery->addShould($orMustNotExists);

        return $boolQuery;
    }

    private function setChoices(): void
    {
        if (null != $this->choices || self::TYPE_TERMS !== $this->type) {
            return;
        }

        $search = $this->clientRequest->initializeCommonSearch($this->queryTypes, $this->queryFilters);

        $aggs = new TermsAggregation($this->name);
        $aggs->setField($this->getField());
        if (null !== $this->aggSize) {
            $aggs->setSize($this->aggSize);
        }

        $sortField = $this->getSortField();
        if (null !== $sortField) {
            $aggs->setOrder($sortField, $this->getSortOrder());
        }

        $nestedPath = $this->getNestedPath();
        if (null === $nestedPath) {
            $search->addAggregation($aggs);
        } else {
            $nested = new Nested($this->name, $nestedPath);
            $nested->addAggregation($aggs);
            $search->addAggregation($nested);
        }
        $search->setSize(0);

        $search = $this->clientRequest->commonSearch($search)->getResponse()->getData();

        $result = $search['aggregations'][$this->name];
        $buckets = $this->isNested() ? $result['nested']['buckets'] : $result['buckets'];
        $choices = [];

        foreach ($buckets as $bucket) {
            $choices[$bucket['key']] = [
                'total' => $bucket['doc_count'],
                'filter' => 0,
                'active' => \in_array($bucket['key'], \is_array($this->value) ? $this->value : []),
            ];
        }

        $this->choices = $choices;
    }

    /**
     * @param array<mixed> $options
     */
    private function setPostFilter(array $options): void
    {
        if (isset($options['post_filter'])) {
            $this->postFilter = (bool) $options['post_filter'];
        } elseif (self::TYPE_TERMS === $this->type && $this->public) {
            $this->postFilter = true; //default post filtering for public terms filters
        } else {
            $this->postFilter = false;
        }
    }
}
