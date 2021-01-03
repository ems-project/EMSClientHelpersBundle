<?php

namespace EMS\ClientHelperBundle\Helper\Search;

use Elastica\Query\AbstractQuery;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequest;
use Symfony\Component\HttpFoundation\Request;

class Search
{
    /** @var string[] */
    private $types;
    /** @var array<string, int> [facet_name => size], used for aggregation */
    private $facets;
    /** @var Synonym[] */
    private $synonyms = [];
    /** @var string[] */
    private $fields = [];
    /** @var string[] */
    private $suggestFields = [];
    /** @var Filter[] */
    private $filters = [];
    /** @var int[] */
    private $sizes;
    /** @var array<mixed> */
    private $defaultSorts;
    /** @var array<mixed> */
    private $sorts;
    /** @var array<mixed> */
    private $highlight = [];

    /** @var string|null free text search */
    private $queryString;
    /** @var array<string, mixed> */
    private $queryFacets = [];

    /** @var int */
    private $page = 0;
    /** @var int */
    private $size = 100;
    /** @var string|null */
    private $sortBy;
    /** @var string */
    private $analyzer;
    /** @var string */
    private $sortOrder = 'asc';

    public function __construct(ClientRequest $clientRequest)
    {
        $options = $this->getOptions($clientRequest);

        if (isset($options['facets'])) {
            @\trigger_error('Deprecated facets, please use filters setting', E_USER_DEPRECATED);
        }

        $this->types = $options['types']; //required
        $this->facets = $options['facets'] ?? [];
        $this->sizes = $options['sizes'] ?? [];
        $this->defaultSorts = $this->parseSorts(($options['default_sorts'] ?? []), $clientRequest->getLocale());
        $this->sorts = $this->parseSorts(($options['sorts'] ?? []), $clientRequest->getLocale());

        $this->setHighlight(($options['highlight'] ?? []), $clientRequest->getLocale());
        $this->setFields(($options['fields'] ?? []), $clientRequest->getLocale());
        $this->setSuggestFields(($options['suggestFields'] ?? $options['fields'] ?? []), $clientRequest->getLocale());
        $this->setAnalyzer(($options['analyzers'] ?? [
            'fr' => 'french',
            'nl' => 'dutch',
            'en' => 'english',
            'de' => 'german',
        ]), $clientRequest->getLocale());
        $this->setSynonyms(($options['synonyms'] ?? []), $clientRequest->getLocale());

        $filters = $options['filters'] ?? [];
        foreach ($filters as $name => $options) {
            $this->filters[$name] = new Filter($clientRequest, $name, $options);
        }
    }

    public function bindRequest(Request $request): void
    {
        $this->queryString = $request->get('q', $this->queryString);
        $this->queryFacets = $request->get('f', $this->queryFacets);

        $this->page = (int) $request->get('p', $this->page);

        $this->setSize($request->get('l', $this->size));
        $this->setSortBy($request->get('s'));
        $this->setSortOrder($request->get('o', $this->sortOrder));

        foreach ($this->filters as $filter) {
            $filter->handleRequest($request);
        }
    }

    /**
     * @param array<mixed> $aggregations
     */
    public function bindAggregations(array $aggregations, ?AbstractQuery $queryFilters): void
    {
        foreach ($aggregations as $name => $aggregation) {
            if ($this->hasFilter($name)) {
                $this->getFilter($name)->handleAggregation($aggregation, $this->getTypes(), $queryFilters);
            }
        }
    }

    /**
     * @return string[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * @return Synonym[]
     */
    public function getSynonyms(): array
    {
        return $this->synonyms;
    }

    /**
     * @return string[]
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getAnalyzer(): string
    {
        return $this->analyzer;
    }

    /**
     * @return string[]
     */
    public function getSuggestFields(): array
    {
        return $this->suggestFields;
    }

    public function hasQueryString(): bool
    {
        return null != $this->queryString;
    }

    public function getQueryString(): ?string
    {
        return $this->queryString;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryFacets(): array
    {
        $queryFacets = [];

        foreach ($this->queryFacets as $field => $terms) {
            if (\array_key_exists($field, $this->facets) && !empty($terms)) {
                $queryFacets[$field] = $terms;
            }
        }

        return $queryFacets;
    }

    public function hasFilter(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    public function getFilter(string $name): Filter
    {
        return $this->filters[$name];
    }

    /**
     * @return Filter[]
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return Filter[]
     */
    public function getActiveFilters()
    {
        return \array_filter($this->filters, function (Filter $filter) {
            return $filter->isActive() && $filter->isPublic();
        });
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getFrom(): int
    {
        return $this->page * $this->size;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * @return int[]
     */
    public function getSizes(): array
    {
        return $this->sizes;
    }

    /**
     * @return array<mixed>
     */
    public function getDefaultSorts(): array
    {
        return $this->defaultSorts;
    }

    /**
     * @return array<mixed>
     */
    public function getSort(): ?array
    {
        return $this->sorts[$this->sortBy] ?? null;
    }

    public function getSortBy(): ?string
    {
        return $this->sortBy;
    }

    public function getSortOrder(): string
    {
        return $this->sortOrder;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSorts(): array
    {
        return $this->sorts;
    }

    /**
     * @return array<mixed>
     */
    public function getHighlight(): array
    {
        return $this->highlight;
    }

    /**
     * @return array<mixed>
     */
    private function getOptions(ClientRequest $clientRequest): array
    {
        if ($clientRequest->getCurrentEnvironment()->hasOption('search_config')) {
            return $clientRequest->getCurrentEnvironment()->getOption('[search_config]');
        }

        if ($clientRequest->hasOption('search_config')) {
            return $clientRequest->getOption('[search_config]');
        }

        if ($clientRequest->hasOption('search')) {
            @\trigger_error('Deprecated search option please use search_config!', E_USER_DEPRECATED);

            return $clientRequest->getOption('[search]');
        }

        throw new \LogicException('no search defined!');
    }

    /**
     * @param array<string, array|string> $sorts
     *
     * @return array<string, array>
     */
    private function parseSorts(array $sorts, string $locale): array
    {
        $result = [];

        foreach ($sorts as $name => $options) {
            if (\is_string($options)) {
                $options = ['field' => $options];
            }

            $options['field'] = \str_replace('%locale%', $locale, $options['field']);

            if ('_score' !== $options['field']) {
                $options['missing'] = '_last';
            }

            $result[$name] = $options;
        }

        return $result;
    }

    /**
     * @param string[] $analyzers
     */
    private function setAnalyzer(array $analyzers, string $locale): void
    {
        $this->analyzer = isset($analyzers[$locale]) ? $analyzers[$locale] : 'standard';
    }

    /**
     * @param string[] $fields
     */
    private function setFields(array $fields, string $locale): void
    {
        $this->fields = \array_map(function (string $field) use ($locale) {
            return \str_replace('%locale%', $locale, $field);
        }, $fields);
    }

    /**
     * @param array<string, string[]> $suggestFields
     */
    private function setSuggestFields(array $suggestFields, string $locale): void
    {
        if (isset($suggestFields[$locale])) {
            $this->suggestFields = $suggestFields[$locale];
        } else {
            $this->suggestFields = [];
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function setHighlight(array $data, string $locale): void
    {
        if (\is_array($data) && isset($data['fields'])) {
            foreach ($data['fields'] as $key => $options) {
                if (\strpos($key, '%locale%')) {
                    $data['fields'][\str_replace('%locale%', $locale, $key)] = $options;
                    unset($data['fields'][$key]);
                }
            }
            $this->highlight = $data;
        }
    }

    private function setSortBy(?string $name): void
    {
        if (null === $name) {
            return;
        }

        if (null == $this->sorts) {
            @\trigger_error('Define possible sort fields with the search option "sorts"', \E_USER_DEPRECATED);
        } elseif (\array_key_exists($name, $this->sorts)) {
            $this->sortBy = $name;
            $this->sortOrder = $this->sorts[$name]['order'] ?? $this->sortOrder;
        }
    }

    private function setSortOrder(string $o): void
    {
        $this->sortOrder = ('asc' === $o || 'desc' === $o) ? $o : 'asc';
    }

    private function setSize(string $l): void
    {
        if (null == $this->sizes) {
            @\trigger_error('Define allow sizes with the search option "sizes"', \E_USER_DEPRECATED);
            $this->size = \intval((int) $l > 0 ? $l : $this->size);
        } elseif (\in_array($l, $this->sizes)) {
            $this->size = (int) $l;
        } else {
            $this->size = (int) \reset($this->sizes);
        }
    }

    /**
     * @param array<mixed> $synonyms
     */
    private function setSynonyms(array $synonyms, string $locale): void
    {
        foreach ($synonyms as $options) {
            if (\is_string($options)) {
                $options = ['types' => [$options]];
            }

            $this->synonyms[] = new Synonym($options, $locale);
        }
    }
}
