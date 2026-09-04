<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Plugin\Review;

use Magento\Review\Block\Form;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\Review\Model\RatingVisibility;

/**
 * THE RATING DIALOG SELF-HEALS RATHER THAN RENDERING WITHOUT STARS.
 *
 * ===========================================================================
 * WHY A SETUP PATCH WAS NOT ENOUGH
 * ===========================================================================
 * `Setup\Patch\Data\PublishProductRatingToStoreViews` writes the
 * `rating_store` rows the storefront needs — and it only runs when somebody
 * runs `setup:upgrade`. A deploy that runs `setup:di:compile`,
 * `setup:static-content:deploy` and `cache:flush` but not `setup:upgrade`
 * therefore ships the new dialog with the OLD data behind it, and the failure
 * is silent in the worst way: the dialog renders, it accepts a comment, the
 * review is saved and approved, and it carries no rating. The panel then reads
 * `0 تقييمات · 3 مراجعات` and nothing anywhere says why.
 *
 * That happened. Diagnosed rather than assumed:
 * `ResourceModel\Rating\Collection::setStoreFilter()` returns early only in
 * SINGLE-STORE mode, and this storefront runs an Arabic and an English store
 * view — so the INNER JOIN on `rating_store` applies, an empty table means an
 * empty collection, and an empty collection means a dialog with no stars in
 * it. Three reviews were submitted through it.
 *
 * A feature whose correctness depends on remembering a console command is a
 * feature that will break again (CLAUDE.md section 5). So the guarantee is
 * moved to the one place that cannot be skipped: the moment the form asks for
 * its ratings.
 *
 * ===========================================================================
 * WHAT IT DOES, AND HOW OFTEN
 * ===========================================================================
 * `getRatings()` came back empty  ->  publish one product rating to this store
 *                                     view, then ask the block again
 * anything else                   ->  pure pass-through, no work at all
 *
 * So it writes AT MOST ONCE per store view, ever. After that the rows exist
 * and this plugin is two comparisons on a cached page.
 *
 * ===========================================================================
 * YES, IT WRITES DURING A GET. HERE IS WHY THAT IS THE RIGHT TRADE.
 * ===========================================================================
 * The alternative is a storefront that quietly collects rating-less reviews
 * until somebody notices — which is exactly the bug this is fixing, and it
 * cost three reviews already. Against that:
 *
 *   - it is idempotent, and it is repair rather than business data;
 *   - it happens on a full-page-cache MISS only, because the review form is
 *     part of the cached PDP;
 *   - `RatingVisibility` never throws — it logs and returns — so a failure
 *     here cannot take a product page down;
 *   - it never overrides an admin's own choice: a store view that already
 *     shows a rating is left completely alone.
 *
 * ===========================================================================
 * THE RE-ENTRY GUARD IS NOT OPTIONAL
 * ===========================================================================
 * Asking `$subject->getRatings()` again goes back through the interceptor, so
 * this plugin sees its own second call. `$healing` stops that becoming
 * unbounded recursion in the one case that would cause it: a store view where
 * publishing reports success but the collection still comes back empty.
 */
class EnsureRatingIsAvailable
{
    private bool $healing = false;

    public function __construct(
        private readonly RatingVisibility $ratingVisibility,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Form $subject
     * @param mixed $result the rating collection core just built
     * @return mixed
     */
    public function afterGetRatings(Form $subject, $result)
    {
        if ($this->healing) {
            return $result;
        }

        if (!$this->isEmpty($result)) {
            return $result;
        }

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return $result;
        }

        // Returns the store ids it actually published to — empty means there
        // was nothing to publish (no active product rating exists at all, which
        // it logs) or this store view already had one.
        if ($this->ratingVisibility->ensureVisibleIn([$storeId]) === []) {
            return $result;
        }

        $this->healing = true;

        try {
            $healed = $subject->getRatings();
        } catch (\Exception $e) {
            $this->logger->error(
                'Spartrak Review: published a product rating but could not re-read the rating list.',
                ['store_id' => $storeId, 'exception' => $e]
            );

            return $result;
        } finally {
            $this->healing = false;
        }

        $this->logger->warning(
            'Spartrak Review: the review form had no rating dimension on this store view, so one was '
            . 'published on the spot. Run bin/magento setup:upgrade so the setup patch records it too.',
            ['store_id' => $storeId]
        );

        return $healed;
    }

    /**
     * `count($collection->getItems())` and not `getSize()`.
     *
     * The collection core hands back is already loaded, so counting its items
     * is free — where `getSize()` issues a second COUNT query against the
     * database on every single product page.
     *
     * @param mixed $result
     */
    private function isEmpty($result): bool
    {
        if ($result === null) {
            return true;
        }

        if (!is_object($result) || !method_exists($result, 'getItems')) {
            return false;
        }

        return count($result->getItems()) === 0;
    }
}
