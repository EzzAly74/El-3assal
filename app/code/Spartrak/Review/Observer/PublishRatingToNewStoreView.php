<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\Store;
use Spartrak\Review\Model\RatingVisibility;

/**
 * A new store view gets a visible product rating too.
 *
 * ===========================================================================
 * WHY A PATCH IS NOT ENOUGH ON ITS OWN
 * ===========================================================================
 * Setup\Patch\Data\PublishProductRatingToStoreViews runs once, over the store
 * views that exist at the time. Magento's rating visibility is stored per store
 * view in `rating_store`, so a store view added afterwards — a second language,
 * a wholesale site — has no row, and on THAT store view the review dialog
 * silently renders with no stars again.
 *
 * That is the same failure this module was written to fix, arriving six months
 * later through a completely ordinary admin action, and nobody would connect
 * the two. So the rule is enforced where store views are created rather than
 * only where the module is installed.
 *
 * ===========================================================================
 * `store_save_after`, AND ONLY FOR A NEW ONE
 * ===========================================================================
 * Magento\Store\Model\Store carries `_eventPrefix = 'store'`, so an admin
 * saving a store view dispatches `store_save_after`. `isObjectNew()` is what
 * separates a creation from an edit — renaming a store view must not re-publish
 * anything, because RatingVisibility would then be asked the question on every
 * save for no reason.
 *
 * The call is a no-op for a store view that already shows a rating, so even if
 * the guard were wrong the worst case is a wasted read.
 */
class PublishRatingToNewStoreView implements ObserverInterface
{
    public function __construct(
        private readonly RatingVisibility $ratingVisibility
    ) {
    }

    public function execute(Observer $observer): void
    {
        $store = $observer->getEvent()->getData('store');

        if (!$store instanceof Store || !$store->isObjectNew()) {
            return;
        }

        $storeId = (int) $store->getId();

        if ($storeId <= 0) {
            return;
        }

        // Never throws: RatingVisibility swallows and logs its own failures, so
        // a rating problem cannot stop an admin creating a store view.
        $this->ratingVisibility->ensureVisibleIn([$storeId]);
    }
}
