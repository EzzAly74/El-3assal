<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Plugin\Review;

use Magento\Framework\Model\AbstractModel;
use Magento\Review\Model\Review;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A review written on one store view is published to ALL of them.
 *
 * ===========================================================================
 * THE PROBLEM: ONE PRODUCT, TWO STOREFRONTS, TWO DIFFERENT TRUTHS
 * ===========================================================================
 * `Magento\Review\Controller\Product\Post` publishes a new review to exactly
 * one store view:
 *
 *     ->setStoreId($this->storeManager->getStore()->getId())
 *     ->setStores([$this->storeManager->getStore()->getId()])
 *
 * This storefront runs the same catalogue in Arabic and in English. So a
 * shopper who reviewed a piston on the Arabic store view had written something
 * that did not exist on the English one: `review_store` had a single row, and
 * every read — Magento's own summary, its rating meter, and this module's
 * histogram, all of which correctly filter by store view — reported one review
 * on `/ar/` and none on `/en/`. The same product, the same shopper, two
 * different products' worth of feedback.
 *
 * That is not a translation problem and this class does not pretend to solve
 * one. The review's TEXT stays in the language it was written in — machine
 * translating a shopper's words and publishing the result under their name is
 * not something a storefront gets to do. What is fixed is VISIBILITY: the
 * review is a fact about the product, so it belongs on every store view that
 * sells the product, in the words its author chose.
 *
 * `review_detail.store_id` is left exactly as the controller set it, which is
 * the record of WHERE it was written — the one piece of per-store information
 * that is genuinely per-store, and what a moderator needs to know which
 * language to expect.
 *
 * ===========================================================================
 * WHY THE RESOURCE MODEL, AND WHY frontend ONLY
 * ===========================================================================
 * The controller sets `stores` inline, a few statements before saving, so a
 * plugin on the controller cannot reach the value. `ResourceModel\Review::save`
 * is the single write path, and core's own `_afterSave` reads `stores` off the
 * object to rewrite `review_store` — so widening the set here is the same
 * mechanism core uses, not a second one.
 *
 * Declared in **etc/frontend/di.xml**, which is the whole of the scoping. The
 * admin's review form has its own "Visible In" multiselect, and a moderator who
 * deliberately restricts a review to one store view has made a decision this
 * plugin must not silently reverse. In `adminhtml` it does not exist.
 *
 * ===========================================================================
 * IT ONLY EVER WIDENS
 * ===========================================================================
 * The union of what the caller asked for and every store view — never a
 * replacement. Store 0 is left to core, which appends it in `_beforeSave`.
 */
class PublishReviewToEveryStoreView
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ReviewResource $subject
     * @param AbstractModel $object
     * @return array{0: AbstractModel}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(ReviewResource $subject, AbstractModel $object): array
    {
        if (!$object instanceof Review) {
            return [$object];
        }

        try {
            $requested = $object->getStores();
            $requested = is_array($requested) ? array_map('intval', $requested) : [];

            $everywhere = $requested;

            foreach ($this->storeManager->getStores(false) as $store) {
                $everywhere[] = (int) $store->getId();
            }

            $everywhere = array_values(array_unique($everywhere));

            /**
             * Only touch the object when this actually adds something. A single
             * store installation, or a review already published everywhere,
             * leaves `stores` byte-identical — which matters because core's
             * `_afterSave` DELETES and re-inserts every row whenever `stores`
             * is non-empty, and there is no reason to rewrite rows that are
             * already correct.
             */
            if (count($everywhere) !== count($requested)) {
                $object->setStores($everywhere);
            }
        } catch (\Exception $e) {
            /**
             * A store-list failure must not lose the shopper's review — it is
             * already validated and about to be written. Left as the caller set
             * it, which is the current behaviour rather than a broken one, and
             * logged because the symptom (a review missing on one store view)
             * gives no clue where to look.
             */
            $this->logger->error('Spartrak Review: could not widen a review to every store view.', [
                'review_id' => (int) $object->getId(),
                'exception' => $e,
            ]);
        }

        return [$object];
    }
}
