<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Plugin\Layer;

use Magento\Catalog\Model\Layer\Filter\Item;
use Magento\Framework\App\Request\Http;
use Magento\LayeredNavigation\Block\Navigation\State;
use Magento\Search\Model\QueryFactory;

/**
 * Makes the layered-navigation "clear" controls drop the SEARCH TERM as well.
 *
 * ===========================================================================
 * WHAT THIS CHANGES, AND WHY IT IS A DECISION AND NOT A FIX
 * ===========================================================================
 * Magento's default is that removing a facet keeps the query: from
 * `?brand=18&q=فورد`, "إزالة" goes to `?q=فورد` — brand gone, search intact.
 * That behaviour is correct by Magento's own model, and it was verified working
 * on this store (the result count moved 442 -> 457 and the chip disappeared).
 *
 * The merchant asked for something different: on this storefront "إزالة" is
 * read as "cancel the filtering", and leaving `q` behind reads as the filter
 * not having been cancelled at all. So both clear controls now drop `q` too:
 *
 *   Item::getRemoveUrl()   the per-chip "إزالة"
 *   State::getClearUrl()   the "مسح الكل" that clears every chip at once
 *
 * Both are covered deliberately. Clearing one chip and clearing all of them are
 * the same gesture to a shopper, so having only one of them reset the search
 * would be incoherent.
 *
 * CONSEQUENCE, STATED PLAINLY: a search-results page with no term is not a page
 * Magento can render — its controller redirects instead. So these links now
 * land on the storefront root, via Spartrak_Search's own ResetEmptySearch
 * plugin. That plugin is REQUIRED, and its absence is not a soft failure: core
 * bounces the termless request back at its referer, and because
 * Mageplaza_AjaxLayer pushState's the link URL BEFORE fetching it, that referer
 * is the termless URL itself. The request redirects to itself until the browser
 * stops with ERR_TOO_MANY_REDIRECTS. The full mechanism is written out in
 * Plugin/Controller/Result/ResetEmptySearch.php. Deploys carrying this module must
 * run setup:di:compile; a cache flush alone does not generate the interceptor
 * that controller needs, because nothing plugged it before.
 *
 * ===========================================================================
 * WHY AN `after` PLUGIN, AND WHY sortOrder MUST BEAT MAGEPLAZA'S
 * ===========================================================================
 * Mageplaza_LayeredNavigation registers its own `aroundGetRemoveUrl` on
 * Magento\Catalog\Model\Layer\Filter\Item (sortOrder 1) and, whenever its
 * module is enabled, it IGNORES $proceed() and builds the URL itself so that
 * multi-select facets can drop one value and keep the rest. Core's method never
 * runs, so anything done inside the call is thrown away — only the returned
 * string can be acted on.
 *
 * Magento nests interceptors by sortOrder: the LOWEST sortOrder is outermost,
 * so its `after` executes LAST. This plugin is therefore registered at
 * sortOrder 0 — below Mageplaza's 1 — which is what guarantees it sees their
 * final URL rather than a URL they are about to replace.
 *
 * ===========================================================================
 * WHY THE QUERY STRING IS EDITED PAIR-BY-PAIR AND NOT REBUILT
 * ===========================================================================
 * These URLs are built with `_escape => true`, so their separators arrive as
 * `&amp;`, and the values are already percent-encoded (Arabic terms especially).
 * Running them back through http_build_query() would re-encode everything and
 * risk changing bytes this plugin has no business changing. Dropping the one
 * matching pair and re-joining leaves every other pair exactly as built.
 */
class ClearSearchTermWithFilters
{
    /**
     * The one route these controls are rewritten on.
     */
    private const SEARCH_ACTION = 'catalogsearch_result_index';

    /**
     * @param Http $request
     */
    public function __construct(
        private readonly Http $request
    ) {
    }

    /**
     * Drop the search term from a single facet's "إزالة" link.
     *
     * @param Item $subject
     * @param string|null $result
     * @return string|null
     */
    public function afterGetRemoveUrl(Item $subject, $result)
    {
        return $this->withoutSearchTerm($result);
    }

    /**
     * Drop the search term from the "مسح الكل" link.
     *
     * @param State $subject
     * @param string|false|null $result
     * @return string|false|null
     */
    public function afterGetClearUrl(State $subject, $result)
    {
        return $this->withoutSearchTerm($result);
    }

    /**
     * Strip the query-term parameter, on the search results route only.
     *
     * @param mixed $url
     * @return mixed the URL unchanged unless it is a search-results URL carrying a term
     */
    private function withoutSearchTerm($url)
    {
        if (!is_string($url) || $url === '') {
            return $url;
        }

        if ($this->request->getFullActionName() !== self::SEARCH_ACTION) {
            return $url;
        }

        return $this->removeQueryParam($url, QueryFactory::QUERY_VAR_NAME);
    }

    /**
     * Remove one parameter from a URL's query string, leaving every other pair byte-identical.
     *
     * @param string $url
     * @param string $name
     * @return string
     */
    private function removeQueryParam(string $url, string $name): string
    {
        $start = strpos($url, '?');

        if ($start === false) {
            return $url;
        }

        $base = substr($url, 0, $start);
        $queryString = substr($url, $start + 1);
        $fragment = '';

        $hash = strpos($queryString, '#');

        if ($hash !== false) {
            $fragment = substr($queryString, $hash);
            $queryString = substr($queryString, 0, $hash);
        }

        // `_escape => true` emits &amp;; a single-pair query has no separator at
        // all, in which case either choice re-joins the same string.
        $separator = str_contains($queryString, '&amp;') ? '&amp;' : '&';

        $kept = array_filter(
            explode($separator, $queryString),
            static function (string $pair) use ($name): bool {
                $key = urldecode(explode('=', $pair, 2)[0]);

                // `q` itself, and the `q[]` form a crafted URL can carry.
                return $key !== $name && !str_starts_with($key, $name . '[');
            }
        );

        if ($kept === []) {
            return $base . $fragment;
        }

        return $base . '?' . implode($separator, $kept) . $fragment;
    }
}
