<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Brand\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Theme\Block\Html\Breadcrumbs;

/**
 * GET /brands — the All Brands landing page (Figma 1368:37277).
 *
 * ===========================================================================
 * IT HOLDS NO BRAND LOGIC AT ALL, ON PURPOSE
 * ===========================================================================
 * The controller's whole job is: return a page, name it, and put two crumbs
 * on it. Which brands exist, what they look like and where a tile goes are
 * answered by Spartrak_Catalog's BrandNavigation, mounted as a view model in
 * this route's layout — the same class the header pane, the mobile drawer and
 * the homepage rail read. A brand added in Stores > Attributes therefore
 * appears on all four surfaces at once, and this file never learns about it.
 *
 * `implements HttpGetActionInterface` on a bare class with constructor DI,
 * rather than extending Magento\Framework\App\Action\Action, which is
 * deprecated — the same shape as Spartrak_InstaPay's transfer controller.
 * GET-only because the page is a pure read: there is nothing to POST here and
 * declaring only the GET interface is what makes that structural.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();

        // set(), not prepend(): this is the whole document title, and the
        // store's title suffix is appended by Magento's own page config.
        $page->getConfig()->getTitle()->set(__('All brands'));

        $this->addBreadcrumbs($page);

        return $page;
    }

    /**
     * Figma 1368:37420 — "الرئيسية ‹ كل الماركات".
     *
     * Magento's own breadcrumbs block, already restyled to the design system
     * in the theme's components/_breadcrumbs.less, so this adds data and no
     * markup.
     *
     * The `if` is not defensive noise: the block is a layout element and a
     * layout that removed it would otherwise fatal here — the same guard, for
     * the same reason, that Spartrak_Search's RemoveSearchCrumb documents.
     */
    private function addBreadcrumbs(Page $page): void
    {
        $breadcrumbs = $page->getLayout()->getBlock('breadcrumbs');

        if (!$breadcrumbs instanceof Breadcrumbs) {
            return;
        }

        $breadcrumbs->addCrumb(
            'home',
            [
                'label' => __('Home'),
                'title' => __('Home'),
                'link' => $this->urlBuilder->getUrl(),
            ]
        );

        // No `link` on the last crumb — it is the page the visitor is already
        // on, and a self-link is a control that does nothing.
        $breadcrumbs->addCrumb(
            'brands',
            [
                'label' => __('All brands'),
                'title' => __('All brands'),
            ]
        );
    }
}
