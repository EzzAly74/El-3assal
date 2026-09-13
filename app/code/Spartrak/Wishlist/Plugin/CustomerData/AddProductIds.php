<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\Plugin\CustomerData;

use Magento\Framework\App\ResourceConnection;
use Magento\Wishlist\CustomerData\Wishlist as WishlistSection;
use Magento\Wishlist\Helper\Data as WishlistHelper;

/**
 * Puts the FULL set of wish-listed product ids in the wishlist customer-data
 * section, so every heart on a page can paint itself.
 *
 * ===========================================================================
 * WHY THE SECTION AND NOT THE TEMPLATE
 * ===========================================================================
 * The product card is inside full-page-cached HTML. Rendering "this one is on
 * your list" server-side would bake ONE customer's wish list into the cache
 * entry every other visitor is then served - a privacy leak first and a
 * correctness bug second. Membership is private content, and private content
 * on this platform means a customer-data section.
 *
 * It also happens to be the cheap answer: the ids arrive with the section
 * payload the page already fetches for the header badge and the minicart, so
 * the hearts cost NO additional request. A per-card lookup, or one endpoint
 * call per grid, would both be worse on the metric that matters.
 *
 * ===========================================================================
 * WHY NOT CORE'S OWN `items`
 * ===========================================================================
 * The section already carries `items`, and it cannot be used for this: core
 * caps it at SIDEBAR_ITEMS_NUMBER (3) because it exists to render a three-row
 * sidebar. A shopper with thirty saved products would get three red hearts and
 * twenty-seven grey ones.
 *
 * ===========================================================================
 * A FLAT LIST OF INTEGERS, AND WHY THAT SHAPE
 * ===========================================================================
 * `[12, 34, 56]` and not `{12: 89, 34: 90}`. The browser never needs the
 * wishlist_item_id - Controller\Ajax\Toggle resolves an item from a product id
 * server-side, precisely so this payload does not have to carry one - and the
 * map form is roughly twice the bytes for a value nothing reads. This rides
 * along on every customer-data fetch, so its size is not free.
 *
 * ===========================================================================
 * TWO INDEXED COLUMNS, NOT A COLLECTION
 * ===========================================================================
 * Magento\Wishlist\Model\ResourceModel\Item\Collection loads the PRODUCTS for
 * every row it returns (_afterLoad -> _assignProducts), with attributes and
 * prices. This needs a list of integers, and it runs on every customer-data
 * fetch for every signed-in shopper, so it reads the one indexed column that
 * answers the question. Same line as WishlistToggle::findItemId(): reads that
 * need no model skip it, writes never do.
 */
class AddProductIds
{
    public function __construct(
        private readonly WishlistHelper $wishlistHelper,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetSectionData(WishlistSection $subject, array $result): array
    {
        $result['product_ids'] = $this->loadProductIds();

        return $result;
    }

    /**
     * @return int[]
     */
    private function loadProductIds(): array
    {
        // The same helper core's own counter uses, memoised per request - so
        // this shares its wish-list load with AddNumericCount rather than
        // causing a second one.
        $wishlist = $this->wishlistHelper->getWishlist();
        $wishlistId = (int) $wishlist->getId();

        if ($wishlistId <= 0) {
            // A guest, or a customer who has never saved anything. Still
            // emitted as an empty array rather than omitted: the browser then
            // has a definite "nothing is saved" and can clear any heart left
            // painted from a previous session, instead of having to treat a
            // missing key as unknown.
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName('wishlist_item'), ['product_id'])
            ->where('wishlist_id = ?', $wishlistId);

        return array_map('intval', $connection->fetchCol($select));
    }
}
