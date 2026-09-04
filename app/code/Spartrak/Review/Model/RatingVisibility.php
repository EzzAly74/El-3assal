<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Model;

use Magento\Review\Model\Rating;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\ResourceModel\Rating as RatingResource;
use Magento\Review\Model\ResourceModel\Rating\CollectionFactory as RatingCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * MAKES A PRODUCT RATING VISIBLE ON THE STOREFRONT.
 *
 * ===========================================================================
 * THE DEFECT THIS EXISTS FOR — verified in core, not guessed
 * ===========================================================================
 * Magento seeds three product ratings on install (Quality, Value, Price) in
 * `Magento\Review\Setup\Patch\Data\InitReviewStatusesAndData`, and it writes
 * **no `rating_store` rows at all**. `Magento\Review\Block\Form::getRatings()`
 * then asks for them with
 *
 *     ->setStoreFilter($storeId)
 *
 * which `ResourceModel\Rating\Collection` implements as an INNER JOIN on
 * `rating_store`. With no rows, the join matches nothing, and the consequence
 * runs the whole length of the feature:
 *
 *   1. the review dialog renders with NO STARS — there is no rating dimension
 *      to draw them for, so the shopper is offered a comment box and nothing
 *      else;
 *   2. every review submitted that way carries no vote in
 *      `rating_option_vote`;
 *   3. so `review_entity_summary.rating_summary` stays null, the product's
 *      star meter renders empty, and the reviews panel's histogram is all
 *      zeroes — "no ratings yet" on a product with two reviews on it.
 *
 * One missing row explains all three. It is fixed by ticking "Visible In" per
 * store view under Stores → Attributes → Rating — which is exactly the kind of
 * invisible manual step CLAUDE.md section 5 says not to leave a feature
 * depending on. A rating dimension the storefront cannot function without is
 * SETUP, not configuration.
 *
 * ===========================================================================
 * ONE DIMENSION, BECAUSE THAT IS WHAT THE DESIGN DRAWS
 * ===========================================================================
 * Figma's dialog (1207:30494) has ONE row of five stars under one question,
 * `ما تقييمك للمنتج؟` — an overall product rating. Publishing all three of
 * Magento's seeded ratings would give three rows with three labels, which is a
 * different design.
 *
 * So this publishes exactly ONE — the first active product rating by position,
 * then by id, which is a stable choice — and leaves the others exactly as they
 * are. They stay invisible on the storefront because they still have no
 * `rating_store` rows, and a merchant who genuinely wants a second dimension
 * ticks it in the admin and gets it: the dialog renders one control per active
 * dimension, labelled with its own name.
 *
 * ===========================================================================
 * IT NEVER TAKES A RATING AWAY
 * ===========================================================================
 * `ensureVisibleIn()` does nothing at all for a store view that already has a
 * visible product rating, whichever one it is. So a merchant who has
 * configured Quality + Value on the Arabic store is untouched, and this class
 * cannot undo an admin's decision — it only fills a hole where there is no
 * decision at all.
 *
 * Store 0 is always included in the set it writes, because that is what core's
 * own admin controller does (`Adminhtml\Rating\Save` merges `[0]` into the
 * posted stores) and a rating that is not in store 0 disappears from the admin
 * form's own "Visible In" state.
 */
class RatingVisibility
{
    public function __construct(
        private readonly RatingCollectionFactory $ratingCollectionFactory,
        private readonly RatingFactory $ratingFactory,
        private readonly RatingResource $ratingResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ensures every store view on this installation can show a star rating.
     *
     * @return int[] the store ids that had none and now do
     */
    public function ensureVisibleEverywhere(): array
    {
        $storeIds = [];

        foreach ($this->storeManager->getStores(false) as $store) {
            $storeIds[] = (int) $store->getId();
        }

        return $this->ensureVisibleIn($storeIds);
    }

    /**
     * @param int[] $storeIds
     * @return int[] the subset that had no visible product rating
     */
    public function ensureVisibleIn(array $storeIds): array
    {
        $storeIds = array_values(array_unique(array_filter(
            array_map('intval', $storeIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($storeIds === []) {
            return [];
        }

        try {
            $ratingIds = $this->getActiveProductRatingIds();

            if ($ratingIds === []) {
                /**
                 * No active product rating exists at all. Creating one would be
                 * inventing a rating dimension rather than publishing one, and
                 * a merchant who has deliberately deactivated every rating has
                 * made a decision this class must not reverse. Logged, because
                 * the storefront's rating dialog will have no stars in it and
                 * that is worth knowing about.
                 */
                $this->logger->warning(
                    'Spartrak Review: no active product rating exists, so the rating dialog will render '
                    . 'no stars. Create or re-activate one under Stores > Attributes > Rating.'
                );

                return [];
            }

            $needed = [];

            foreach ($storeIds as $storeId) {
                if (!$this->hasVisibleRating($ratingIds, $storeId)) {
                    $needed[] = $storeId;
                }
            }

            if ($needed === []) {
                return [];
            }

            $ratingId = (int) reset($ratingIds);

            /** @var Rating $rating */
            $rating = $this->ratingFactory->create();
            // load() populates `stores` from rating_store — see the resource
            // model's _afterLoad — so the union below extends the existing set
            // rather than replacing it. processRatingStores() diffs old against
            // new, so nothing an admin ticked is dropped.
            $this->ratingResource->load($rating, $ratingId);

            if ((int) $rating->getId() !== $ratingId) {
                return [];
            }

            $existing = array_map('intval', (array) $rating->getStores());
            $rating->setStores(array_values(array_unique(array_merge($existing, $needed, [0]))));
            $this->ratingResource->save($rating);

            $this->logger->info(
                'Spartrak Review: published a product rating to store views that had none, '
                . 'so the rating dialog can draw its stars.',
                ['rating_id' => $ratingId, 'store_ids' => $needed]
            );

            return $needed;
        } catch (\Exception $e) {
            // A failure here must never break a setup:upgrade or a store save.
            // The symptom is a star-less dialog, which is visible and logged.
            $this->logger->error('Spartrak Review: could not publish a product rating to a store view.', [
                'store_ids' => $storeIds,
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * Active product ratings, most-preferred first.
     *
     * `setPositionOrder()` is the admin's own ordering, so the "first" rating is
     * the one a merchant has put at the top of the list rather than whichever
     * row the database happened to return.
     *
     * @return int[]
     */
    private function getActiveProductRatingIds(): array
    {
        $collection = $this->ratingCollectionFactory->create()
            // The entity CODE, which addEntityFilter() accepts alongside an id —
            // and core's own constant rather than the literal, so this cannot
            // drift from the value Magento's seed patch writes.
            ->addEntityFilter(Rating::ENTITY_PRODUCT_CODE)
            ->setActiveFilter(true)
            ->setPositionOrder();

        $ids = [];

        foreach ($collection as $rating) {
            $ids[] = (int) $rating->getId();
        }

        return $ids;
    }

    /**
     * @param int[] $ratingIds
     */
    private function hasVisibleRating(array $ratingIds, int $storeId): bool
    {
        foreach ($ratingIds as $ratingId) {
            // The resource's own public reader, so this class writes no SQL of
            // its own (CLAUDE.md section 17).
            if (in_array($storeId, array_map('intval', $this->ratingResource->getStores($ratingId)), true)) {
                return true;
            }
        }

        return false;
    }
}
