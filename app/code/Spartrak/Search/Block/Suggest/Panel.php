<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Search\Block\Suggest;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Spartrak\Search\Model\SuggestionProvider;

/**
 * View model for the search-suggestions panel.
 *
 * Holds every formatting decision the template would otherwise have to make —
 * price formatting, image resizing, count strings — so panel.phtml stays
 * markup plus escaping, per CLAUDE.md §8's "no business logic in .phtml".
 *
 * A Template block rather than a ViewModel because the controller instantiates
 * it directly (there is no page layout to attach an argument to).
 */
class Panel extends Template
{
    /** @var array{query:string,total:int,products:ProductInterface[],terms:array,result_url:string}|null */
    private ?array $data = null;

    public function __construct(
        Context $context,
        private readonly SuggestionProvider $provider,
        private readonly ImageHelper $imageHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Resolved once per render — the template reads it several times.
     */
    private function resolve(): array
    {
        if ($this->data === null) {
            $this->data = $this->provider->get((string) $this->getData('query_text'));
        }

        return $this->data;
    }

    public function getQueryText(): string
    {
        return $this->resolve()['query'];
    }

    public function getTotal(): int
    {
        return $this->resolve()['total'];
    }

    /**
     * @return ProductInterface[]
     */
    public function getProducts(): array
    {
        return $this->resolve()['products'];
    }

    /**
     * @return array<int, array{title:string,num_results:int,url:string}>
     */
    public function getTerms(): array
    {
        return $this->resolve()['terms'];
    }

    public function getResultUrl(): string
    {
        return $this->resolve()['result_url'];
    }

    /**
     * True when there is nothing at all to show. The controller returns an
     * empty body in that case so the client can close the panel without
     * having to parse it.
     */
    public function isEmpty(): bool
    {
        return $this->getTotal() === 0 && $this->getTerms() === [];
    }

    /**
     * The card image, at the SAME image id the PLP card uses.
     *
     * Reused deliberately rather than declaring a new one sized to Figma's
     * 157px card: `category_page_grid` is 300x300, which is already ~2x the
     * card's rendered size, and every product that can appear here has almost
     * certainly been through that exact resize for a category or search page
     * already. So the panel serves files that are already on disk and often
     * already in the shopper's browser cache — no new resize work, no new
     * cache entries, and one fewer image profile to keep in view.xml
     * (CLAUDE.md §13: better caching, fewer requests).
     */
    public function getImage(ProductInterface $product): ImageHelper
    {
        /** @var Product $product */
        return $this->imageHelper->init($product, 'category_page_grid');
    }

    /**
     * Figma's card (node 864:8925) shows ONE price, in brand blue — no
     * was/now pair, no tier hint. getMinimalPrice() on the final price is the
     * value that is correct for every product type: identical to the final
     * price for a simple product, and the "from" figure for a configurable or
     * bundle, which is what a single-price card must show.
     */
    public function getPriceHtml(ProductInterface $product): string
    {
        /** @var Product $product */
        $amount = $product->getPriceInfo()
            ->getPrice(FinalPrice::PRICE_CODE)
            ->getMinimalPrice();

        return $this->priceCurrency->format(
            $amount->getValue(),
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            $this->_storeManager->getStore()
        );
    }

    /**
     * Blocks rendered by this module's AJAX controller must never be
     * block-cached: the whole response varies by query string, and the
     * controller already owns a query-aware cache of its own.
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setCacheLifetime(null);
    }
}
