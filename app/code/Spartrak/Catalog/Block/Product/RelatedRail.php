<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Catalog\Block\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Block\Product\ProductList\Related;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Checkout\Model\ResourceModel\Cart as CartResourceModel;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Module\Manager;

/**
 * PDP — "منتجات أخري من جون دير" (Figma 535:12047 desktop / 655:19767 mobile).
 *
 * ===========================================================================
 * NOTHING NEW IS BUILT HERE. THIS CLASS ONLY CONNECTS TWO EXISTING THINGS.
 * ===========================================================================
 * The rail component already exists (Spartrak_Homepage's section header +
 * _homepage-sections.less + spartrak-home-carousel.js) and the card already
 * exists (Spartrak_Catalog::product/card.phtml + _product-card.less). Magento's
 * related-products block already exists and already renders on this page.
 *
 * What was missing is the four answers the shared rail header asks of whatever
 * block renders it — getTitle(), getViewAllUrl(), showsCarouselNav(),
 * getHeadingId() — plus handing each product to the shared card. That is this
 * file, and that is all of it. No visual value is defined here, and none is
 * defined for the PDP rail anywhere else: _pdp.less only undoes the homepage's
 * page gutter, which this page already supplies itself.
 *
 * ===========================================================================
 * THE DATA STAYS MAGENTO'S OWN
 * ===========================================================================
 * Extends the platform's Related block, so the rail shows exactly the products
 * an admin picked under a product's "Related Products" tab, in the position
 * order they dragged there. The only brand-derived thing is the HEADING, which
 * Figma writes the brand into — see getTitle().
 *
 * ===========================================================================
 * WHY _prepareData() IS OVERRIDDEN
 * ===========================================================================
 * Core's version is three statements and this keeps all three, adding two
 * things core's related list has no need of and this rail does:
 *
 *  1. THE REVIEW-SUMMARY JOIN. The card draws a rating. Magento appends
 *     rating_summary/reviews_count to a listing collection through the
 *     `catalog_block_product_list_collection` event, which Magento_Review
 *     observes — but ONLY ListProduct dispatches it
 *     (vendor/magento/module-catalog/Block/Product/ListProduct.php:514), so a
 *     related collection arrives with no rating and every card would paint an
 *     empty star row. Dispatching the same event is the native mechanism, not
 *     a workaround: one JOIN on a collection that is being loaded anyway, and
 *     any other module observing that event decorates this rail exactly as it
 *     decorates the PLP.
 *
 *     It must run BEFORE load() — the observer joins summary columns into the
 *     SELECT — which is why this cannot be parent::_prepareData() followed by
 *     a decoration.
 *
 *  2. A PAGE SIZE. Related products are unbounded in the admin; a merchant who
 *     links forty products would otherwise ship forty cards, forty images and
 *     forty add-to-cart forms into the PDP's HTML. The cap is a layout
 *     argument, so changing it is one line and no PHP.
 *
 * Stock filtering is deliberately NOT added: Magento_CatalogInventory already
 * plugs into link collections and applies the store's own "Display Out of Stock
 * Products" setting.
 */
class RelatedRail extends Related
{
    /**
     * Attribute the heading reads. Same code ViewModel\BrandNavigation uses;
     * that class owns the header's brand pane and is left alone.
     */
    private const BRAND_ATTRIBUTE = 'brand';

    /**
     * Cards rendered when layout passes no `limit`. Figma draws five in the
     * viewport; the rail scrolls, so this is how deep it goes, not how many
     * are visible.
     */
    private const DEFAULT_LIMIT = 12;

    /**
     * Every card here is below the fold on every viewport — under the gallery,
     * the buy box AND the tabs — so no image in it is ever eager and none
     * competes with the PDP's LCP element (the gallery image). The shared card
     * reads any index >= 4 as below-fold.
     */
    private const BELOW_FOLD_INDEX = 99;

    /**
     * Core's own three statements, plus the review join and the cap.
     *
     * @return $this
     */
    protected function _prepareData()
    {
        $product = $this->getProduct();

        $this->_itemCollection = $product->getRelatedProductCollection()
            ->addAttributeToSelect('required_options')
            ->setPositionOrder()
            ->addStoreFilter();

        if ($this->moduleManager->isEnabled('Magento_Checkout')) {
            // Name, images, urls, final/minimal price and tax percents — the
            // whole set the shared card paints, resolved by Magento's own "used
            // in product listing" configuration rather than a hand-maintained
            // attribute list here.
            $this->_addProductAttributesAndPrices($this->_itemCollection);
        }

        $this->_itemCollection->setVisibility(
            $this->_catalogProductVisibility->getVisibleInCatalogIds()
        );

        $this->_itemCollection->setPageSize($this->getLimit());

        $this->_eventManager->dispatch(
            'catalog_block_product_list_collection',
            ['collection' => $this->_itemCollection]
        );

        $this->_itemCollection->load();

        foreach ($this->_itemCollection as $item) {
            $item->setDoNotUseCategoryId(true);
        }

        return $this;
    }

    /**
     * @return ProductInterface[]
     */
    public function getRailProducts(): array
    {
        return array_values(iterator_to_array($this->getItems()));
    }

    /**
     * A rail with one card has nothing to scroll, so it gets no arrows, no
     * progress bar and no carousel widget — the rule the homepage rails follow.
     */
    public function isCarousel(): bool
    {
        return count($this->getItems()) > 1;
    }

    /** Part of the contract the shared rail header expects. */
    public function showsCarouselNav(): bool
    {
        return $this->isCarousel();
    }

    /**
     * Figma writes the brand into the heading: "منتجات أخري من جون دير".
     *
     * Read from the product's real `brand` attribute, so the heading follows
     * the store view's own option labels and stays admin-owned (CLAUDE.md §7).
     * With no brand there is nothing truthful to interpolate, so it falls back
     * to Magento's own "Related Products" wording rather than inventing a
     * phrase. Both strings are translated in this module's i18n/ar_EG.csv.
     */
    public function getTitle(): string
    {
        $brand = $this->getBrandLabel();

        return $brand === ''
            ? (string) __('Related products')
            : (string) __('More products from %1', $brand);
    }

    /**
     * NO LINK — the shared header renders the "view all" chip only when this
     * returns a URL, so returning '' leaves the header with its arrows alone,
     * which is exactly what Figma's desktop rail draws (535:12049).
     *
     * Figma's MOBILE header does draw one ("الكل", 655:19769). It is not
     * guessed at here because where it should lead is an open merchant
     * question — a brand listing? the category? — and a link to the wrong page
     * is worse than no link. One line here once that is answered.
     */
    public function getViewAllUrl(): string
    {
        return '';
    }

    /**
     * Stable id for the section's aria-labelledby. One rail per PDP, so it
     * needs no per-instance suffix.
     */
    public function getHeadingId(): string
    {
        return 'spartrak-pdp-related-title';
    }

    /**
     * THE EXISTING rail header — Spartrak_Homepage::section/head.phtml, the
     * same partial every homepage section renders, unmodified.
     *
     * fetchView() rather than a child block, for the same reason the homepage
     * sections use it: the partial renders against this block's own context, so
     * the header costs no extra block instantiation.
     */
    public function getHeadHtml(): string
    {
        return $this->fetchView($this->getTemplateFile('Spartrak_Homepage::section/head.phtml'));
    }

    /**
     * One product, through the SHARED Card - Product component — the same
     * template the PLP, the search grid and the homepage rails render
     * (04-COMPONENT-INVENTORY.md's single "Card - Product" rule).
     *
     * Same call shape as Spartrak_Homepage's ProductCarousel::renderCard(),
     * including the shared ListProduct for `list_block`, so the card receives
     * exactly what it receives on the homepage and needs no change of its own.
     */
    public function renderCard(ProductInterface $product): string
    {
        try {
            return $this->getLayout()
                ->createBlock(\Magento\Framework\View\Element\Template::class)
                ->setTemplate('Spartrak_Catalog::product/card.phtml')
                ->setData('product', $product)
                ->setData('list_block', $this->getListBlock())
                ->setData('index', self::BELOW_FOLD_INDEX)
                ->toHtml();
        } catch (\Exception $exception) {
            // One unrenderable card must not take the PDP down with it; the
            // rail shows the others. Logged, never swallowed (CLAUDE.md §9).
            $this->_logger->error(
                'Spartrak_Catalog: related card render failed for product '
                . $product->getId() . ': ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return '';
        }
    }

    /**
     * A shared ListProduct instance for the card's getAddToCartUrl(), created
     * ONCE per rail and handed to every card — the homepage rail's own
     * arrangement, kept identical so the card behaves identically.
     */
    private function getListBlock(): ?ListProduct
    {
        if ($this->hasData('list_block')) {
            return $this->getData('list_block');
        }

        try {
            $listBlock = $this->getLayout()->createBlock(ListProduct::class);
        } catch (\Exception $exception) {
            // The card degrades gracefully without it: no add-to-cart button,
            // everything else still renders.
            $this->_logger->warning(
                'Spartrak_Catalog: could not create ListProduct block: ' . $exception->getMessage()
            );
            $listBlock = null;
        }

        $this->setData('list_block', $listBlock);

        return $listBlock;
    }

    /** `limit` layout argument, clamped to something sane. */
    private function getLimit(): int
    {
        $limit = (int) ($this->getData('limit') ?: self::DEFAULT_LIMIT);

        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /** The current product's brand, as the store view's own option label. */
    private function getBrandLabel(): string
    {
        $product = $this->getProduct();

        if ($product === null) {
            return '';
        }

        try {
            $label = $product->getAttributeText(self::BRAND_ATTRIBUTE);
        } catch (\Exception $exception) {
            // No such attribute on this install, or no source model on it.
            return '';
        }

        // getAttributeText() returns a Phrase for some source models and an
        // array for multiselects; neither is a heading on its own.
        if (is_array($label)) {
            $label = reset($label);
        }

        return trim((string) $label);
    }
}
