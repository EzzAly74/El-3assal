<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * ONE-TIME BACKFILL: existing reviews become visible on every store view.
 *
 * Plugin\Review\PublishReviewToEveryStoreView fixes this going forward, and it
 * can only fix reviews written after it is deployed. The reviews already on the
 * catalogue were published to whichever store view the shopper happened to be
 * on, so the Arabic store view shows them and the English one does not — which
 * is the state that made a piston read "2 reviews" in Arabic and none in
 * English.
 *
 * ===========================================================================
 * IT ONLY WIDENS REVIEWS THAT ARE ALREADY PUBLISHED SOMEWHERE
 * ===========================================================================
 * The `WHERE EXISTS` is the whole safety of this patch. A review with NO
 * `review_store` rows is invisible on every storefront, and that may well be
 * deliberate — a moderator can clear the "Visible In" list to take a review
 * down without deleting it. Publishing those would resurrect content somebody
 * chose to hide, on every store view at once, silently.
 *
 * So a review is widened only if it is already visible on at least one real
 * store view. That is a review whose author's intent — and the moderator's —
 * is unambiguous: it is published, and it should be published to the whole
 * catalogue it belongs to.
 *
 * Store 0 is not touched: core appends it in `ResourceModel\Review::_beforeSave`
 * and it is an admin-visibility marker, not a storefront.
 *
 * ===========================================================================
 * WHY insertOnDuplicate AND NOT delete-then-insert
 * ===========================================================================
 * Core's own `_afterSave` rewrites the whole set for a review, which is right
 * when a single review is being saved and wrong here: this touches every review
 * on the store, and a delete pass would drop rows for store views that were
 * removed but whose reviews are still wanted if that store view ever comes
 * back. Adding rows and never removing any is the smaller, reversible
 * operation, and it makes re-running the patch a no-op.
 */
class PublishExistingReviewsToStoreViews implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        try {
            $connection = $this->moduleDataSetup->getConnection();
            $reviewStore = $this->moduleDataSetup->getTable('review_store');

            $storeIds = [];

            foreach ($this->storeManager->getStores(false) as $store) {
                $storeIds[] = (int) $store->getId();
            }

            if (count($storeIds) < 2) {
                // A single-store installation has nothing to widen to.
                $this->moduleDataSetup->endSetup();

                return $this;
            }

            // Reviews that are already published on at least one real store
            // view — see the class header for why that qualifier is the
            // safety of this patch.
            $published = $connection->fetchCol(
                $connection->select()
                    ->distinct(true)
                    ->from($reviewStore, ['review_id'])
                    ->where('store_id > ?', 0)
            );

            $rows = [];

            foreach ($published as $reviewId) {
                foreach ($storeIds as $storeId) {
                    $rows[] = ['review_id' => (int) $reviewId, 'store_id' => $storeId];
                }
            }

            if ($rows !== []) {
                // The table's primary key is (review_id, store_id), so an
                // existing pair is left exactly as it is.
                $connection->insertOnDuplicate($reviewStore, $rows, ['store_id']);
            }

            $this->logger->info(
                'Spartrak Review: existing reviews were published to every store view.',
                ['reviews' => count($published), 'store_views' => count($storeIds)]
            );
        } catch (\Exception $e) {
            // A backfill must never break setup:upgrade. The forward fix (the
            // frontend plugin) is unaffected, and the symptom is only that
            // OLD reviews stay on one store view.
            $this->logger->error('Spartrak Review: could not publish existing reviews to every store view.', [
                'exception' => $e,
            ]);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
