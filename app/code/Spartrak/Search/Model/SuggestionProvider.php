<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Search\Model\AutocompleteInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Everything the suggestions panel needs for one query, resolved once.
 *
 * ===========================================================================
 * WHY THIS EXISTS RATHER THAN Magento's OWN SUGGEST ENDPOINT
 * ===========================================================================
 * Figma's panel (desktop node 864:8879) shows a result COUNT, a rail of
 * matching PRODUCTS and a list of suggested TERMS. Magento's
 * `search/ajax/suggest` returns only the third — AutocompleteInterface items,
 * which carry a title and a num_results and nothing else. There is no product
 * data behind it to render a card from, and CLAUDE.md §5 rules out inventing
 * any. So the terms still come from core's own autocomplete (no reimplemented
 * suggestion logic), and the products come from a real catalogsearch query
 * run here.
 *
 * ===========================================================================
 * WHY THE FULLTEXT SEARCH COLLECTION — AND WHY THE TYPE HINT IS THE BASE CLASS
 * ===========================================================================
 * Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection is `@api` in
 * 2.4.8 and is the collection Magento\Catalog\Model\Layer\Search puts behind
 * the search results page — so this endpoint and the results page the "view
 * all" link leads to are answered by ONE search implementation, and the count
 * shown in the panel is the count the shopper will land on.
 *
 * The Layer itself is deliberately not reused: it mutates shared layer state
 * and resolves a category context that an AJAX suggestion has no business
 * touching. The collection alone is the part that matters.
 *
 * TWO TRAPS, both hit on the first compile of this class:
 *
 *   1. `Fulltext\CollectionFactory` IS NOT A CLASS. It is a virtualType
 *      (module-catalog-search/etc/di.xml line 98) over
 *      Catalog\Model\ResourceModel\Product\CollectionFactory. A virtual type
 *      cannot be a constructor type hint — there is no file for reflection to
 *      read — and `setup:di:compile` fails outright with "Class ... does not
 *      exist". The hint is therefore the REAL base factory, and di.xml injects
 *      the virtual type as the argument. That is exactly the pattern core uses
 *      for its own consumer, Layer\Category\ItemCollectionProvider.
 *
 *   2. THE PLAIN FACTORY WOULD RUN THE WRONG SEARCH. Collection's constructor
 *      defaults `$searchRequestName` to 'catalog_view_container' — the CATEGORY
 *      BROWSE request. _renderFiltersBefore() branches on that name, and a
 *      keyword search needs 'quick_search_container'. Core wires that through a
 *      second virtual type, `Fulltext\SearchCollectionFactory`, which is what
 *      Layer\Search\ItemCollectionProvider — the search results page — is given.
 *      So SearchCollectionFactory is the one injected here; the plain
 *      CollectionFactory would have compiled (had trap 1 not bitten first) and
 *      quietly searched with the wrong request, making the panel's results and
 *      count disagree with the results page this file claims to match.
 *
 * See etc/di.xml, which is where both of those decisions actually live.
 *
 * ===========================================================================
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ===========================================================================
 * No price formatting, no image resizing, no escaping. Those are view
 * concerns and belong to the block (CLAUDE.md §8) — this returns real Product
 * models and lets the view render them.
 */
class SuggestionProvider
{
    public function __construct(
        /**
         * Hinted as the base factory, injected as
         * Fulltext\SearchCollectionFactory by etc/di.xml — see the class note.
         * At runtime create() returns a Fulltext\Collection bound to
         * 'quick_search_container'.
         */
        private readonly ProductCollectionFactory $collectionFactory,
        private readonly AutocompleteInterface $autocomplete,
        private readonly Visibility $visibility,
        private readonly ProductStatus $productStatus,
        private readonly SearchHelper $searchHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{
     *     query: string,
     *     total: int,
     *     products: ProductInterface[],
     *     terms: array<int, array{title: string, num_results: int, url: string}>,
     *     result_url: string
     * }
     */
    public function get(string $queryText): array
    {
        $queryText = $this->normalize($queryText);

        $empty = [
            'query' => $queryText,
            'total' => 0,
            'products' => [],
            'terms' => [],
            'result_url' => $this->resultUrl($queryText),
        ];

        if ($queryText === '') {
            return $empty;
        }

        $products = [];
        $total = 0;

        if ($this->config->getProductLimit() > 0) {
            [$total, $products] = $this->searchProducts($queryText);
        }

        return [
            'query' => $queryText,
            'total' => $total,
            'products' => $products,
            'terms' => $this->terms($queryText),
            'result_url' => $this->resultUrl($queryText),
        ];
    }

    /**
     * @return array{0: int, 1: ProductInterface[]}
     */
    private function searchProducts(string $queryText): array
    {
        $collection = $this->collectionFactory->create();

        // Only what the card renders (Figma node 864:8905: image, name, price)
        // — every extra attribute here is a column loaded for every row.
        $collection->addAttributeToSelect(['name', 'small_image', 'thumbnail']);
        $collection->addSearchFilter($queryText);
        $collection->setVisibility($this->visibility->getVisibleInSearchIds());
        $collection->addAttributeToFilter('status', ['in' => $this->productStatus->getVisibleStatusIds()]);

        // Final price for the card. addMinimalPrice covers the composite
        // product types whose card price is a "from" figure.
        $collection->addMinimalPrice();
        $collection->addFinalPrice();
        $collection->addTaxPercents();

        $collection->setPageSize($this->config->getProductLimit());
        $collection->setCurPage(1);

        // getSize() is the TOTAL match count from the search engine, not the
        // page size — it is what the panel's "N products" heading and the
        // "view all (N)" link report, and it costs no extra query because the
        // engine returns it with the same response.
        $total = (int) $collection->getSize();

        return [$total, array_values($collection->getItems())];
    }

    /**
     * Core's own autocomplete terms.
     *
     * AutocompleteInterface::getItems() takes no arguments — it reads the
     * query from the request through Magento\Search\Model\QueryFactory, whose
     * QUERY_VAR_NAME is 'q'. That is precisely why this module's controller
     * accepts the query on `q` and not on a name of its own: changing the
     * parameter would silently return terms for an empty query.
     *
     * @return array<int, array{title: string, num_results: int, url: string}>
     */
    private function terms(string $queryText): array
    {
        $limit = $this->config->getTermLimit();

        if ($limit === 0) {
            return [];
        }

        $terms = [];

        foreach ($this->autocomplete->getItems() as $item) {
            $data = $item->toArray();
            $title = isset($data['title']) ? trim((string) $data['title']) : '';

            if ($title === '') {
                continue;
            }

            $terms[] = [
                'title' => $title,
                'num_results' => (int) ($data['num_results'] ?? 0),
                'url' => $this->resultUrl($title),
            ];

            if (count($terms) >= $limit) {
                break;
            }
        }

        return $terms;
    }

    /**
     * The catalogsearch results URL for a term.
     *
     * Built from the search helper's own result route so it always matches
     * where the storefront's search form posts, and urlencode()d because a
     * part number legitimately contains characters ("/", "+", "#") that would
     * otherwise truncate the query string.
     */
    private function resultUrl(string $queryText): string
    {
        $base = $this->searchHelper->getResultUrl();

        if ($queryText === '') {
            return $base;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'q=' . urlencode($queryText);
    }

    /**
     * Trim, collapse whitespace and enforce the store's own maximum query
     * length — the same cap Magento applies to a submitted search, so a
     * suggestion can never be run against a longer string than the results
     * page would accept.
     */
    private function normalize(string $queryText): string
    {
        $queryText = trim(preg_replace('/\s+/u', ' ', $queryText) ?? '');

        if ($queryText === '') {
            return '';
        }

        $maxLength = (int) $this->searchHelper->getMaxQueryLength();

        if ($maxLength > 0 && mb_strlen($queryText) > $maxLength) {
            $queryText = mb_substr($queryText, 0, $maxLength);
        }

        return $queryText;
    }

    /**
     * The store's minimum query length. Below this the panel stays shut —
     * checked by the caller so a one-character keystroke never reaches the
     * search engine at all.
     */
    public function getMinQueryLength(): int
    {
        return (int) $this->searchHelper->getMinQueryLength(
            $this->storeManager->getStore()->getId()
        );
    }
}
