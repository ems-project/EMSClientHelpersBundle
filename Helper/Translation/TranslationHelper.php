<?php

namespace EMS\ClientHelperBundle\Helper\Translation;

use EMS\ClientHelperBundle\Helper\Cache\CacheHelper;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequest;
use EMS\ClientHelperBundle\Helper\Elasticsearch\ClientRequestManager;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBagInterface;

class TranslationHelper
{
    /** @var ClientRequestManager */
    private $manager;
    /** @var TranslatorBagInterface */
    private $translator;
    /** @var CacheHelper */
    private $cache;
    /** @var array */
    private $locales;

    public function __construct(ClientRequestManager $manager, Translator $translator, CacheHelper $cache, array $locales)
    {
        $this->manager = $manager;
        $this->translator = $translator;
        $this->cache = $cache;
        $this->locales = $locales;
    }

    public function addCatalogues(): void
    {
        foreach ($this->manager->all() as $clientRequest) {
            if (!$clientRequest->hasOption('translation_type')) {
                continue;
            }

            if (!$clientRequest->mustBeBind() && !$clientRequest->isBind()) {
                continue;
            }

            $messages = $this->getMessages($clientRequest);

            foreach ($this->locales as $locale) {
                if (!isset($messages[$locale])) {
                    continue;
                }

                $clientCatalog = new MessageCatalogue($locale);
                $clientCatalog->add($messages[$locale], $clientRequest->getCacheKey());

                $catalogue = $this->translator->getCatalogue($locale);
                $catalogue->addCatalogue($clientCatalog);
            }
        }
    }

    private function getMessages(ClientRequest $clientRequest): array
    {
        $cacheItem = $this->cache->get($clientRequest->getCacheKey('translations'));

        $type = $clientRequest->getOption('[translation_type]');
        $lastChanged = $clientRequest->getLastChangeDate($type);

        if ($this->cache->isValid($cacheItem, $lastChanged)) {
            return $this->cache->getData($cacheItem);
        }

        $messages = $this->createMessages($clientRequest, $type);
        $this->cache->save($cacheItem, $messages);

        return $messages;
    }

    private function createMessages(ClientRequest $clientRequest, string $type): array
    {
        $messages = [];
        $scroll = $clientRequest->scrollAll([
            'size' => 100,
            'type' => $type,
            'sort' => ['_doc'],
        ], '5s');

        foreach ($scroll as $hit) {
            foreach ($this->locales as $locale) {
                if (isset($hit['_source']['label_'.$locale])) {
                    $messages[$locale][$hit['_source']['key']] = $hit['_source']['label_'.$locale];
                }
            }
        }

        return $messages;
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(): void
    {
        $this->addCatalogues();
    }
}
