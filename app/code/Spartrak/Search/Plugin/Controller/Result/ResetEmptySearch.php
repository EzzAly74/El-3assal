<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Plugin\Controller\Result;

use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Search\Model\QueryFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Answers a termless search request instead of bouncing it back at its own referer.
 *
 * ===========================================================================
 * THE ORIGINAL BUG
 * ===========================================================================
 * On a results page, clearing the input and pressing "بحث" left the previous
 * term in place: same URL, same heading, same products, term still in the box.
 *
 * It is core's own last branch of
 * Magento\CatalogSearch\Controller\Result\Index::execute() (2.4.8):
 *
 *     if ($queryText != '') {
 *         ...
 *     } else {
 *         $this->getResponse()->setRedirect($this->_redirect->getRedirectUrl());
 *     }
 *
 * getRedirectUrl() is "go back where you came from" — the REFERER. Submitting
 * an empty query from /catalogsearch/result/?q=X makes that results page its
 * own referer, so Magento redirects the search straight back onto the stale
 * term. The box refills from the request on the way back
 * (Search\Helper\Data::getEscapedQueryText()), which is what makes it look
 * cached. Nothing is cached; the request is simply bounced.
 *
 * ===========================================================================
 * THE SECOND BUG, AND WHY THIS PLUGIN IS NOT OPTIONAL
 * ===========================================================================
 * Since Plugin\Layer\ClearSearchTermWithFilters, "إزالة" and "مسح الكل" drop
 * the term as well, so both now point at a TERMLESS results URL and land in
 * core's else-branch above. Mageplaza_AjaxLayer then turns that click into:
 *
 *     window.history.pushState({url: submitUrl}, '', submitUrl);   // FIRST
 *     storage.get(submitUrl)                                       // then XHR
 *
 * (app/code/Mageplaza/AjaxLayer/view/frontend/web/js/action/submit-filter.js).
 * pushState runs BEFORE the request and rewrites document.URL, so the XHR that
 * follows carries the termless URL as its OWN Referer. Core then redirects it
 * to the referer — itself — and the browser gives up at the redirect limit:
 *
 *     GET /ar/catalogsearch/result/index/  net::ERR_TOO_MANY_REDIRECTS
 *
 * The extension's .fail() handler answers that with window.location.reload(),
 * which reloads the pushState'd URL and reproduces the same loop as a visible
 * navigation. That is the error page the merchant reported.
 *
 * A referer-driven redirect on a URL that is a NAVIGATION TARGET is a loop
 * waiting to happen, and this plugin is what stops it: the one case where the
 * referer is itself a results page is exactly the case it declines to pass on.
 * Without it compiled in, clearing a filter is a hard site error, so
 * `setup:di:compile` is a required step of any deploy that carries this module.
 *
 * ===========================================================================
 * WHY XHR GETS JSON AND A NAVIGATION GETS A 302
 * ===========================================================================
 * Both are the same answer — "there is nothing to show here, go to the
 * storefront root" — in the two encodings the two callers understand.
 *
 * A 302 is useless to the AJAX layer: XMLHttpRequest follows redirects itself,
 * so the extension would receive the destination's HTML and try to read
 * `response.products` off a full document. Mageplaza publishes the answer for
 * exactly this, in submit-filter.js:
 *
 *     if (response.backUrl) { window.location = response.backUrl; return; }
 *
 * so an XHR is answered with {"backUrl": ...} and the extension performs the
 * navigation itself. This is the extension's own contract, not a workaround
 * layered on top of it, which is why nothing under app/code/Mageplaza is
 * touched (CLAUDE.md section 2 — third-party code is not ours to edit).
 *
 * ===========================================================================
 * WHY THE FIX IS SCOPED TO THAT ONE CASE
 * ===========================================================================
 * Core's behaviour is RIGHT everywhere else. Clearing the box on a product
 * page and submitting should return you to that product page, and it does.
 * The loop exists only when the referer is itself a search-results page, so
 * that is the only case this declines, and it declines it in core's own terms:
 * the store base URL is exactly what getRedirectUrl() itself falls back to when
 * there is no referer at all. No new destination is invented.
 *
 * ===========================================================================
 * WHY A PLUGIN, AND WHY `around`
 * ===========================================================================
 * The decision lives inline in execute(); there is no event, no layout hook and
 * no protected seam to override. execute() is public, so it is interceptable,
 * and `around` is the only plugin type that can decline the original call — a
 * `before` plugin could not stop core from setting the bad redirect, and an
 * `after` plugin would run once it already had.
 *
 * A preference would mean copying core's whole execute() — every future fix to
 * the cacheable/non-cacheable result paths frozen at 2.4.8 — to change one
 * branch. This touches none of it: a non-empty query never reaches our code.
 *
 * Returning a Result rather than calling setRedirect() on the response is the
 * 2.4.8-native form; Action::dispatch() returns `$result ?: $this->_response`,
 * so a ResultInterface handed back from execute() is what FrontController
 * renders.
 */
class ResetEmptySearch
{
    /**
     * Schemes/paths of the search results route are resolved per store, so a
     * multi-store or multi-language install compares against its OWN URL.
     */
    private const SEARCH_RESULT_ROUTE = 'catalogsearch/result';

    /**
     * The key Mageplaza_AjaxLayer reads to perform a real navigation.
     *
     * @see app/code/Mageplaza/AjaxLayer/view/frontend/web/js/action/submit-filter.js
     */
    private const AJAX_REDIRECT_KEY = 'backUrl';

    /**
     * @param QueryFactory $queryFactory
     * @param RedirectInterface $redirect
     * @param UrlInterface $url
     * @param StoreManagerInterface $storeManager
     * @param ResultFactory $resultFactory
     * @param Http $request
     */
    public function __construct(
        private readonly QueryFactory $queryFactory,
        private readonly RedirectInterface $redirect,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResultFactory $resultFactory,
        private readonly Http $request
    ) {
    }

    /**
     * Send a termless search that came FROM the results page to the storefront root.
     *
     * @param Index $subject
     * @param callable $proceed
     * @return ResultInterface|null
     */
    public function aroundExecute(Index $subject, callable $proceed)
    {
        // The same memoised Query instance core is about to read, so the
        // emptiness test here and core's cannot drift apart (trimming, the
        // `?q[]=` array guard and max-length truncation all already applied).
        if ($this->queryFactory->get()->getQueryText() !== '') {
            return $proceed();
        }

        if (!$this->isRefererSearchResults()) {
            // No loop to break — core's "back to where you came from" stands.
            return $proceed();
        }

        $destination = $this->storeManager->getStore()->getBaseUrl();

        if ($this->request->isXmlHttpRequest()) {
            // The AJAX layered navigation. A 302 here would be followed by
            // XMLHttpRequest itself and hand the extension a page of HTML it
            // cannot read — or, when the referer is the pushState'd termless
            // URL, followed round and round until the browser gives up.
            return $this->resultFactory->create(ResultFactory::TYPE_JSON)
                ->setData([self::AJAX_REDIRECT_KEY => $destination]);
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setUrl($destination);
    }

    /**
     * Whether the request came from a search-results page of this same store.
     *
     * @return bool
     */
    private function isRefererSearchResults(): bool
    {
        $referer = (string) $this->redirect->getRefererUrl();

        if ($referer === '') {
            return false;
        }

        $resultUrl = $this->url->getUrl(self::SEARCH_RESULT_ROUTE);

        $refererParts = parse_url($referer);
        $resultParts = parse_url($resultUrl);

        if (!is_array($refererParts) || !is_array($resultParts)) {
            return false;
        }

        // Same-origin only. A foreign referer is never grounds for us to decide
        // anything, even though the destination here is our own base URL and so
        // could not be an open redirect either way.
        if (($refererParts['host'] ?? null) !== ($resultParts['host'] ?? null)) {
            return false;
        }

        $refererPath = $this->normalisePath($refererParts['path'] ?? '');
        $resultPath = $this->normalisePath($resultParts['path'] ?? '');

        if ($refererPath === '/' || $resultPath === '/') {
            return false;
        }

        // Prefix, not equality: the layered-navigation "remove" links and the
        // pager address the same page through the explicit `.../result/index/`
        // form, and both are search-results pages for this purpose.
        return str_starts_with($refererPath, $resultPath);
    }

    /**
     * Normalise a URL path to a single trailing slash so prefixes compare cleanly.
     *
     * @param string $path
     * @return string
     */
    private function normalisePath(string $path): string
    {
        return rtrim($path, '/') . '/';
    }
}
