<?php
/**
 * Drops the "Search results for: '<term>'" crumb from the search results page.
 *
 * ===========================================================================
 * WHY A PLUGIN AND NOT LAYOUT XML
 * ===========================================================================
 * The crumb is not declared anywhere a layout file can reach it. It is added
 * imperatively, by Magento\CatalogSearch\Block\Result::_prepareLayout()
 * (2.4.8, lines 85-106 — read from the tag, because this install's vendor/ is
 * a skeleton of empty files):
 *
 *     $title = $this->getSearchQueryText();
 *     $this->pageConfig->getTitle()->set($title);
 *     $breadcrumbs = $this->getLayout()->getBlock('breadcrumbs');
 *     if ($breadcrumbs) {
 *         $breadcrumbs->addCrumb('home',   [...])
 *                     ->addCrumb('search', ['label' => $title, 'title' => $title]);
 *     }
 *
 * The only lever layout XML has is `<referenceBlock name="breadcrumbs"
 * remove="true"/>`, which is all-or-nothing: it would take the "الرئيسية"
 * crumb with it, and the design keeps that one.
 *
 * _prepareLayout() is protected, so it cannot be intercepted directly.
 * Magento\Theme\Block\Html\Breadcrumbs::addCrumb() is public, and is the seam.
 *
 * ===========================================================================
 * WHY `around` AND NOT `before`
 * ===========================================================================
 * A `before` plugin can rewrite the arguments but cannot decline the call, and
 * declining is the entire point. `around` is the only plugin type that can
 * skip the original method.
 *
 * It must return $subject, not null: addCrumb() ends with `return $this` and
 * core relies on it — `addCrumb('home', ...)->addCrumb('search', ...)` is a
 * chain, so returning anything else fatals on the very next call.
 *
 * ===========================================================================
 * WHY THE ROUTE GUARD
 * ===========================================================================
 * 'search' is a plausible crumb name for any page with a search step in its
 * trail (advanced search, an admin-configured CMS route). Scoping to the
 * catalogsearch results action means this can only ever remove the one crumb
 * it was written for.
 *
 * The page title and the <h1> are untouched: both are set BEFORE and OUTSIDE
 * the `if ($breadcrumbs)` guard above, so suppressing the crumb cannot affect
 * them. That is what makes removing the crumb — rather than the block — safe.
 *
 * Doing this server-side rather than with `display: none` also keeps the
 * separator correct: components/_breadcrumbs.less draws it with
 * `li:not(:last-child)::after`, so a hidden-but-present search crumb would
 * leave "الرئيسية" followed by a chevron pointing at nothing.
 */
declare(strict_types=1);

namespace Spartrak\Search\Plugin\Block\Html;

use Magento\Framework\App\Request\Http;
use Magento\Theme\Block\Html\Breadcrumbs;

class RemoveSearchCrumb
{
    /**
     * The crumb name core passes for the search term.
     */
    private const SEARCH_CRUMB = 'search';

    /**
     * Route/controller/action of the catalog search results page.
     */
    private const SEARCH_RESULTS_ACTION = 'catalogsearch_result_index';

    /**
     * The CONCRETE request, not RequestInterface: getFullActionName() is
     * declared on Magento\Framework\App\Request\Http and is absent from the
     * interface, which carries only getModuleName()/getActionName().
     *
     * @var Http
     */
    private $request;

    public function __construct(Http $request)
    {
        $this->request = $request;
    }

    /**
     * @param  Breadcrumbs $subject
     * @param  callable    $proceed
     * @param  string      $crumbName
     * @param  array       $crumbInfo
     * @return Breadcrumbs
     */
    public function aroundAddCrumb(
        Breadcrumbs $subject,
        callable $proceed,
        $crumbName,
        $crumbInfo
    ) {
        if ($crumbName === self::SEARCH_CRUMB
            && $this->request->getFullActionName() === self::SEARCH_RESULTS_ACTION
        ) {
            return $subject;
        }

        return $proceed($crumbName, $crumbInfo);
    }
}
