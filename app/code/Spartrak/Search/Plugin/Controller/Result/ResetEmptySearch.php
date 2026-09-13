<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Plugin\Controller\Result;

use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\UrlInterface;
use Magento\Search\Model\QueryFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Clears the search when the box is submitted empty from the results page.
 *
 * ===========================================================================
 * THE BUG
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
 * no protected seam to override, and `$allowedMimeTypes`-style DI is not on
 * offer either. execute() is public, so it is interceptable, and `around` is
 * the only plugin type that can decline the original call — a `before` plugin
 * could not stop core from setting the bad redirect, and an `after` plugin
 * would run once it already had.
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
     * @param QueryFactory $queryFactory
     * @param RedirectInterface $redirect
     * @param UrlInterface $url
     * @param StoreManagerInterface $storeManager
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        private readonly QueryFactory $queryFactory,
        private readonly RedirectInterface $redirect,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * Send an empty search submitted FROM the results page back to the storefront root.
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

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setUrl($this->storeManager->getStore()->getBaseUrl());

        return $redirect;
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
