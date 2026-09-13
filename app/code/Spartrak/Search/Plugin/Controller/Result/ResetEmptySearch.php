<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Plugin\Controller\Result;

use Magento\Framework\App\ActionInterface;
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
 * It is the last branch of the search results controller, identical in core
 * (Magento\CatalogSearch\Controller\Result\Index, 2.4.8) and in the module
 * that replaces it here:
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
 * THE REDIRECT LOOP THIS GUARDS AGAINST
 * ===========================================================================
 * A referer-driven redirect on a URL that is also a NAVIGATION TARGET is a
 * loop waiting to happen, and this storefront hit it. While "إزالة" and
 * "مسح الكل" were rewritten to drop the term as well, both pointed at a
 * TERMLESS results URL and landed in that else-branch — and
 * Mageplaza_AjaxLayer turns such a click into:
 *
 *     window.history.pushState({url: submitUrl}, '', submitUrl);   // FIRST
 *     storage.get(submitUrl)                                       // then XHR
 *
 * (app/code/Mageplaza/AjaxLayer/view/frontend/web/js/action/submit-filter.js).
 * pushState runs BEFORE the request and rewrites document.URL, so the XHR that
 * follows carries the termless URL as its OWN Referer. The controller redirects
 * it to the referer — itself — until the browser gives up:
 *
 *     GET /ar/catalogsearch/result/index/  net::ERR_TOO_MANY_REDIRECTS
 *
 * The extension's .fail() handler answers that with window.location.reload(),
 * which reloads the pushState'd URL and reproduces the loop as a visible
 * navigation. That was the error page the merchant reported.
 *
 * THAT REWRITE IS GONE. Removing a facet keeps the search term again, which is
 * Magento's own model and the only reading with a renderable destination: the
 * term is the QUERY and facets NARROW it, so cancelling a facet widens the
 * query rather than abandoning it. Measured on this store, dropping the brand
 * chip takes "فورد" from one page of results to thirty-eight — the filter
 * really does lift, which was the complaint the rewrite was meant to answer.
 * A "clear" that also cleared the query had nowhere to land, and the redirect
 * it needed in order to land anywhere is what produced the loop above.
 *
 * The guard stays, because the loop is still reachable: onpopstate replays a
 * history entry's URL through this same AJAX path, and sessions that browsed
 * while the rewrite was live still hold termless entries. The one case where
 * the referer is itself a results page is exactly the case this plugin
 * declines to pass on.
 *
 * ===========================================================================
 * WHY TWO TYPES ARE REGISTERED IN di.xml, AND WHY THE HINT IS ActionInterface
 * ===========================================================================
 * `catalogsearch/result/index` is NOT served by core's controller on this
 * install. Mageplaza_AjaxLayer/etc/frontend/di.xml declares
 *
 *     <preference for="Magento\CatalogSearch\Controller\Result\Index"
 *                 type="Mageplaza\AjaxLayer\Controller\Search\Result\Index"/>
 *
 * and that class extends Magento\Framework\App\Action\Action — NOT the core
 * controller. Magento inherits plugins down a class hierarchy, but there is no
 * hierarchy here, so a plugin registered only on the core type is configured
 * onto a class the frontend never instantiates and silently does nothing. It
 * cannot be compiled into existence; it is on the wrong class.
 *
 * (Smartwave/porto declares the same preference in its GLOBAL etc/di.xml.
 * Mageplaza's is area-specific, and an area preference wins on the frontend.)
 *
 * Both types are therefore registered, so the behaviour is the storefront's
 * and not a particular extension's: whichever controller is in force — with
 * Mageplaza enabled, disabled, or removed — this runs. That is also why the
 * subject is hinted as ActionInterface, the one type both controllers share
 * (Action extends AbstractAction implements ActionInterface); a concrete hint
 * would fatal the moment the other controller is the one in force.
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
 * navigation itself. This mirrors what its controller already does on the
 * non-empty branch, which serves JSON to `isAjax()` and HTML otherwise — the
 * empty branch simply never got the same treatment. It is the extension's own
 * contract, not a workaround layered on top of it, which is why nothing under
 * app/code/Mageplaza is touched (CLAUDE.md section 2 — third-party code is not
 * ours to edit).
 *
 * ===========================================================================
 * WHY THE FIX IS SCOPED TO THAT ONE CASE
 * ===========================================================================
 * The controller's behaviour is RIGHT everywhere else. Clearing the box on a
 * product page and submitting should return you to that product page, and it
 * does. The loop exists only when the referer is itself a search-results page,
 * so that is the only case this declines, and it declines it in core's own
 * terms: the store base URL is exactly what getRedirectUrl() itself falls back
 * to when there is no referer at all. No new destination is invented.
 *
 * ===========================================================================
 * WHY A PLUGIN, AND WHY `around`
 * ===========================================================================
 * The decision lives inline in execute(); there is no event, no layout hook and
 * no protected seam to override. execute() is public, so it is interceptable,
 * and `around` is the only plugin type that can decline the original call — a
 * `before` plugin could not stop the controller setting the bad redirect, and
 * an `after` plugin would run once it already had.
 *
 * A preference would mean copying a whole execute() — and there are two of them
 * in play, one of which is a third-party class already holding the preference
 * slot. This touches neither: a non-empty query never reaches our code.
 *
 * Returning a Result rather than calling setRedirect() on the response is the
 * 2.4.8-native form; Action::dispatch() ends `return $result ?: $this->_response`
 * (vendor/magento/framework/App/Action/Action.php:115), so a ResultInterface
 * handed back from execute() is what FrontController renders.
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
     * @param ActionInterface $subject the search results controller in force — see the class note
     * @param callable $proceed
     * @return ResultInterface|null
     */
    public function aroundExecute(ActionInterface $subject, callable $proceed)
    {
        // The same memoised Query instance the controller is about to read, so
        // the emptiness test here and its own cannot drift apart (trimming, the
        // `?q[]=` array guard and max-length truncation all already applied).
        if ($this->queryFactory->get()->getQueryText() !== '') {
            return $proceed();
        }

        if (!$this->isRefererSearchResults()) {
            // No loop to break — "back to where you came from" stands.
            return $proceed();
        }

        $destination = $this->storeManager->getStore()->getBaseUrl();

        // isAjax() rather than isXmlHttpRequest(): it is the same gate the
        // Mageplaza controller uses on its own non-empty branch, so both
        // branches agree on what an AJAX request is.
        if ($this->request->isAjax()) {
            // A 302 here would be followed by XMLHttpRequest itself and hand
            // the extension a page of HTML it cannot read — or, when the
            // referer is the pushState'd termless URL, followed round and
            // round until the browser gives up.
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
