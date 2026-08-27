<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Controller\Ajax;

use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use Spartrak\Search\Block\Suggest\Panel;
use Spartrak\Search\Model\Config;
use Spartrak\Search\Model\SuggestionProvider;

/**
 * GET /search-suggest/ajax/suggest?q=...
 *
 * Returns the rendered suggestions panel as an HTML fragment, or an empty
 * 200 body when there is nothing to suggest.
 *
 * ===========================================================================
 * WHY HTML AND NOT JSON
 * ===========================================================================
 * A JSON payload would have to be turned into DOM by a client-side template,
 * which means shipping and running a templating step for markup this project
 * already knows how to render server-side. Returning HTML keeps the panel's
 * structure in a .phtml where Magento's escaping helpers apply (CLAUDE.md
 * §17) and where the theme can override it, and leaves the browser with
 * nothing to do but insert a string — the "less JavaScript" side of
 * CLAUDE.md §13. The response is small and gzips well.
 *
 * ===========================================================================
 * CACHING
 * ===========================================================================
 * Search-as-you-type is the hottest read path on the storefront: every
 * debounced keystroke is an Elasticsearch round trip plus a product
 * collection load. The rendered fragment is therefore cached, but only under
 * a key that carries EVERY dimension the output actually varies on —
 * store, currency and customer group (prices are group-specific, and serving
 * one group's prices to another would be a correctness bug, not a
 * performance win). Tagged with the catalog so a product save clears it
 * regardless of the configured lifetime.
 *
 * The endpoint is GET-only and read-only: no form key, no CSRF surface.
 */
class Suggest implements HttpGetActionInterface
{
    /**
     * Matches Magento\Search\Model\QueryFactory::QUERY_VAR_NAME. Core's
     * AutocompleteInterface reads the query from the request under this exact
     * name, so it is not ours to rename — see SuggestionProvider::terms().
     */
    private const QUERY_PARAM = 'q';

    private const CACHE_PREFIX = 'spartrak_search_suggest_';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly LayoutInterface $layout,
        private readonly CacheInterface $cache,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly SuggestionProvider $provider,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8');
        // The response is per-shopper (prices) and already cached server-side;
        // no shared proxy should hold a copy of it.
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);

        if (!$this->config->isEnabled()) {
            return $result->setContents('');
        }

        $queryText = trim((string) $this->request->getParam(self::QUERY_PARAM, ''));

        // Below the store's own minimum query length there is nothing to
        // search for — stop before touching the search engine at all.
        if ($queryText === '' || mb_strlen($queryText) < $this->provider->getMinQueryLength()) {
            return $result->setContents('');
        }

        $lifetime = $this->config->getCacheLifetime();
        $cacheKey = $this->cacheKey($queryText);

        if ($lifetime > 0) {
            $cached = $this->cache->load($cacheKey);

            if ($cached !== false) {
                return $result->setContents((string) $cached);
            }
        }

        $html = $this->render($queryText);

        if ($lifetime > 0) {
            $this->cache->save($html, $cacheKey, [CatalogProduct::CACHE_TAG], $lifetime);
        }

        return $result->setContents($html);
    }

    private function render(string $queryText): string
    {
        /** @var Panel $block */
        $block = $this->layout->createBlock(Panel::class);
        $block->setTemplate('Spartrak_Search::suggest/panel.phtml');
        $block->setData('query_text', $queryText);

        // An empty panel returns an empty BODY rather than empty markup, so
        // the client can treat "nothing to show" as a falsy response and close
        // the panel without parsing anything.
        return $block->isEmpty() ? '' : $block->toHtml();
    }

    /**
     * Every dimension the rendered fragment varies on. Customer group is the
     * one that matters most — it is what makes the prices in the rail correct
     * for the shopper looking at them.
     */
    private function cacheKey(string $queryText): string
    {
        return self::CACHE_PREFIX . sha1(implode('|', [
            $this->storeManager->getStore()->getId(),
            $this->storeManager->getStore()->getCurrentCurrencyCode(),
            $this->customerSession->getCustomerGroupId(),
            $this->config->getProductLimit(),
            $this->config->getTermLimit(),
            mb_strtolower($queryText),
        ]));
    }
}
