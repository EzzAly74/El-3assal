<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\ViewModel;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Wishlist\Model\Item;

/**
 * "المنتجات المفضلة" — the per-row facts the wishlist card needs. Figma 821:17006.
 *
 * ===========================================================================
 * WHAT IT IS FOR
 * ===========================================================================
 * Everything else the card prints comes off blocks Magento already gives the
 * template: `Magento\Wishlist\Block\Customer\Wishlist::getWishlistItems()`
 * pages the collection, `getProductUrl($item)` links the row, and
 * `getItemPriceHtml($item)` renders the configured price through the platform's
 * own pricing pipeline. This view model exists for the two answers that are
 * not on those blocks and that a template must not work out for itself
 * (CLAUDE.md §8): which image to draw, and whether the product can still be
 * bought.
 *
 * ===========================================================================
 * IT REUSES THE ORDER CARD'S IMAGE ROLE, DELIBERATELY
 * ===========================================================================
 * `spartrak_account_order_item_thumbnail` — the role the theme's etc/view.xml
 * already declares at 216x216 for the order-history card's 96px thumb.
 *
 * That is not a shortcut. Figma builds this page's row from the SAME component
 * as the order card ("Card - Order History", 821:17006) with the same 96x96
 * Image Container (821:17036), so it is the same picture at the same size. A
 * second role — `wishlist_small_image`, which core's own
 * AbstractBlock::getImageUrl() reaches for — would generate and cache a second
 * derivative of every wishlisted product for a visually identical result, and
 * every new role multiplies the resize work a catalogue of 8,908 SKUs does on
 * first request (the reasoning etc/view.xml records where the role is
 * declared).
 *
 * Returning the HELPER rather than a URL is what lets the template emit real
 * `width`/`height` attributes, which is how the row avoids shifting as the
 * images arrive (CLAUDE.md §11 — CLS).
 */
class WishlistItem implements ArgumentInterface
{
    /**
     * The theme's own product-listing thumbnail role. See the class header for
     * why this page does not add one of its own.
     */
    private const IMAGE_ID = 'spartrak_account_order_item_thumbnail';

    public function __construct(
        private readonly ImageHelper $imageHelper
    ) {
    }

    /**
     * Null when the row's product has since been deleted, so the template can
     * draw an empty frame instead of a broken image.
     */
    public function getItemImage(Item $item): ?ImageHelper
    {
        $product = $item->getProduct();

        return $product !== null && $product->getId()
            ? $this->imageHelper->init($product, self::IMAGE_ID)
            : null;
    }

    /**
     * Whether "شراء الآن" should be offered at all.
     *
     * Core's own cart column asks the same question the same way before it
     * renders its add-to-cart control: an out-of-stock or disabled product
     * gets no button, because posting one would fail at the cart with a
     * message the shopper cannot act on.
     */
    public function isBuyable(Item $item): bool
    {
        $product = $item->getProduct();

        return $product !== null && $product->getId() && (bool) $product->isSaleable();
    }
}
