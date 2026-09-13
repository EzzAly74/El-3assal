<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Plugin\PageCache;

use Magento\Framework\App\PageCache\IdentifierInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;

/**
 * Gives an XHR its own full-page-cache entry, so a URL that answers with JSON
 * for a script and HTML for a browser can never hand one the other's response.
 *
 * NEW2B-5650 — "in case of filtering with brands and canceling the filter, the
 * result still filtered".
 *
 * ===========================================================================
 * THE DEFECT, MEASURED ON THE LIVE STORE
 * ===========================================================================
 * Mageplaza_AjaxLayer answers ONE category URL with TWO CONTENT TYPES.
 * Plugin\Controller\Category\View::afterExecute() returns
 * `application/json` ({products, navigation}) when the request is an XHR and
 * the whole `text/html` page when it is not.
 *
 * Magento\Framework\App\PageCache\Identifier::getValue() keys on
 * [isSecure, uriString, varyString] and NOTHING ELSE — no request headers. The
 * same identifier is used to look up and to store (BuiltinPlugin on the front
 * controller, HttpPlugin on the response), so both kinds of response compete
 * for one entry and whichever request arrives first decides what everyone
 * else gets:
 *
 *   * a URL first warmed by a plain page view then serves the full HTML page
 *     to the script's XHR;
 *   * a URL first warmed by an XHR then serves raw JSON to a real browser
 *     navigation.
 *
 * Verified against https://051258281d.nxcli.io/ar on a category with a brand
 * filter, using a fresh `probe=` value per trial so every URL started cold,
 * and reproduced in both orders. The JSON-to-a-browser direction is proof the
 * store's own FPC is the cache doing this: no upstream HTML cache could
 * produce a JSON body.
 *
 * The first case is the reported bug. The shopper presses إزالة, the script
 * fetches the now-unfiltered URL, receives an HTML document where it expects
 * {products, navigation}, has nothing it can swap in, and the filtered list
 * stays on screen. It fails specifically when REMOVING a filter because the
 * bare category URL is the warmest HTML entry in the catalogue — it is the
 * page every shopper and every crawler lands on. Applying a filter usually
 * reaches a URL nobody has requested as a page, which is why that direction
 * appeared to work and only cancelling looked broken.
 *
 * The second case was not reported and is worse: a filter URL whose first
 * visitor is the script serves raw JSON to a browser, and to Googlebot.
 *
 * ===========================================================================
 * WHY THE CACHE KEY AND NOT THE URL
 * ===========================================================================
 * The alternative was to give the XHR a URL of its own — append `isAjax=1` in
 * a JS mixin so the two never share an entry. It works, and it was rejected on
 * measurement: Magento builds every layered-nav and toolbar URL with
 * `_current => true`, which copies the CURRENT QUERY STRING into the generated
 * link (Magento\Framework\Url::setRouteParams, the `$this->_request->getQuery()`
 * loop). Measured on the live store, `?brand=16&isAjax=1` came back with the
 * marker in 8 of 8 navigation hrefs and in every sort link — so the fix would
 * have had to strip a parameter back out of third-party HTML on the client, and
 * any of those links opened in a new tab would have served JSON to a browser.
 *
 * Keying the cache instead needs NO JavaScript at all (CLAUDE.md section 13
 * ranks less JS above fewer requests), changes no URL, adds nothing to the DOM
 * and leaves the AJAX filtering the project wants exactly as it is.
 *
 * ===========================================================================
 * WHAT THIS COSTS, WHICH IS AS CLOSE TO NOTHING AS THE FIX CAN BE
 * ===========================================================================
 * PERFORMANCE IS THIS PROJECT'S FIRST PRIORITY (section 4), and the ordinary
 * page path is the one that must not move. It does not: the early return below
 * means a normal browser request produces the IDENTICAL identifier it produced
 * before this plugin existed, so every warm entry stays warm, nothing is
 * invalidated by deploying this, and no shopper's LCP changes.
 *
 * Only requests that declare themselves XHR are re-keyed. The extra entries
 * are therefore exactly the set of URLs that are fetched BOTH ways — today the
 * category filter URLs, i.e. precisely the ones that are broken now. Nothing
 * else in the catalogue gains an entry.
 *
 * ===========================================================================
 * WHY `isXmlHttpRequest()` AND NOT `isAjax()`
 * ===========================================================================
 * Magento's isAjax() is also true when an `ajax` or `isAjax` QUERY PARAMETER is
 * present — and a parameter is already part of the URI, so it already keys the
 * cache. Reading the header alone keeps this plugin's discriminator aligned
 * with the one thing the identifier cannot see, and keeps it from re-keying a
 * request that needs no help.
 */
class SeparateXhrCacheEntries
{
    /**
     * Appended before re-hashing. Any constant would do; it is named so a
     * cache entry dumped during debugging is recognisable.
     */
    private const XHR_MARKER = '|xhr';

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @param IdentifierInterface $subject
     * @param string $result the identifier core computed
     * @return string
     */
    public function afterGetValue(IdentifierInterface $subject, $result)
    {
        // The ordinary page request leaves here with core's own value. See the
        // performance note in the header: this is what keeps existing cache
        // entries valid and the shopper-facing path unchanged.
        if (!$this->request instanceof HttpRequest || !$this->request->isXmlHttpRequest()) {
            return $result;
        }

        // Re-hashed rather than concatenated so the return value keeps the
        // shape core promises — a single hash — for anything downstream that
        // uses it as a cache-id fragment.
        return sha1($result . self::XHR_MARKER);
    }
}
