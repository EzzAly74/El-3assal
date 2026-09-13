<?php
/**
 * Copyright © Spartrak. All rights reserved.
 */
declare(strict_types=1);

namespace Spartrak\Wishlist\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Wishlist\Helper\Data as WishlistHelper;

/**
 * Every product id in the current visitor's wish list, read once per request.
 *
 * ===========================================================================
 * WHY THIS EXISTS: CORE'S ITEM COUNT IS CAPPED AT THREE
 * ===========================================================================
 * The header badge used to read Magento\Wishlist\Helper\Data::getItemCount(),
 * which is what core's own counter phrase counts. It stops at 3 — a shopper
 * who saved five products saw "3" — and the cause is a state leak in core that
 * no amount of care on our side can avoid:
 *
 *   1. Helper\Data::getWishlistItemCollection() MEMOISES one collection
 *      instance for the whole request (Helper/Data.php, $_wishlistItemCollection).
 *
 *   2. Magento\Wishlist\CustomerData\Wishlist::getItems() takes that shared
 *      instance and mutates it:
 *
 *          $collection->clear()->setPageSize(self::SIDEBAR_ITEMS_NUMBER)
 *
 *      SIDEBAR_ITEMS_NUMBER is 3. That is correct for what getItems() wants —
 *      a three-item sidebar preview — but the page size now sticks to the
 *      shared collection for the rest of the request.
 *
 *   3. Helper\Data::calculate() then counts THAT collection with
 *
 *          $count = $collection->count();
 *
 *      and Magento\Framework\Data\Collection::count() is the Countable
 *      implementation: it calls load() and counts the loaded rows, so it
 *      RESPECTS the limit. getSize() would not — it builds a COUNT select and
 *      strips the limit — but calculate() does not use getSize().
 *
 *   4. calculate() writes that 3 into the customer session
 *      (setWishlistItemCount), and getItemCount() serves the session value
 *      from then on. The badge is stuck at 3 until something recalculates the
 *      count from an uncapped collection.
 *
 * So the ceiling is not a coincidence and not our arithmetic: it is exactly
 * SIDEBAR_ITEMS_NUMBER, leaking out of the sidebar preview into the counter.
 *
 * ===========================================================================
 * WHY A DIRECT COUNT AND NOT A SECOND COLLECTION
 * ===========================================================================
 * Anything that goes through the memoised collection inherits whatever state
 * the last caller left on it, so the fix has to be ORDER-INDEPENDENT — it must
 * give the same answer whether or not getItems() has already run. Building our
 * own item collection would achieve that, but it would also mean restating
 * core's filters, and CLAUDE.md section 9 rules out duplicating business logic
 * to work around a defect.
 *
 * One indexed read of wishlist_item is neither: it is the table core's own
 * collection reads, keyed by the wish list this helper already resolved, and it
 * cannot be poisoned by a page size because it never touches the collection.
 *
 * ===========================================================================
 * ONE SOURCE FOR THE BADGE AND THE HEARTS
 * ===========================================================================
 * Plugin\CustomerData\AddProductIds already ran this exact query to paint the
 * hearts, and AddNumericCount needed a number. Both now come from here, so the
 * numeral in the header and the set of filled hearts on the page are the same
 * fact counted two ways and CANNOT disagree — which the previous pairing (a
 * session-cached count beside a live id list) could, and did.
 *
 * Memoised per request because both plugins run on every customer-data section
 * load, and the ids do not change between them.
 *
 * ===========================================================================
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ===========================================================================
 * It counts SAVED ITEMS. It does not apply core's in-stock filter and does not
 * honour wishlist/wishlist_link/use_qty (which makes core's phrase sum item
 * quantities instead). That is the badge Figma draws — a numeral beside a heart
 * meaning "this many things are saved" (node 595:14506) — and it is the only
 * reading under which the number agrees with the hearts the shopper can see.
 *
 * Consequence, recorded rather than hidden: with `use_qty` enabled, or with an
 * out-of-stock saved product, this numeral can differ from core's `counter`
 * phrase, which the link's accessible label still uses. If that ever needs to
 * match, the phrase is the thing to change, not this.
 */
class SavedProductIds
{
    /**
     * @var int[]|null
     */
    private ?array $ids = null;

    public function __construct(
        private readonly WishlistHelper $wishlistHelper,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return int[]
     */
    public function getIds(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }

        $this->ids = $this->load();

        return $this->ids;
    }

    public function getCount(): int
    {
        return count($this->getIds());
    }

    /**
     * @return int[]
     */
    private function load(): array
    {
        $wishlistId = (int) $this->wishlistHelper->getWishlist()->getId();

        if ($wishlistId <= 0) {
            // A guest, or a customer who has never saved anything. Still an
            // empty array rather than nothing: the browser then has a definite
            // "nothing is saved" and can clear any heart left painted from a
            // previous session, instead of having to treat a missing key as
            // unknown.
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName('wishlist_item'), ['product_id'])
            ->where('wishlist_id = ?', $wishlistId);

        return array_map('intval', $connection->fetchCol($select));
    }
}
