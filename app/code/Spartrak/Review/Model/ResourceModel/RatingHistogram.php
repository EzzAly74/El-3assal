<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;
use Psr\Log\LoggerInterface;

/**
 * How many people gave this product each star value — Figma 535:10097.
 *
 * ===========================================================================
 * WHY THIS QUERY HAS TO EXIST
 * ===========================================================================
 * Magento aggregates reviews two ways and neither is a histogram:
 *
 *   review_entity_summary          one row per product: an average percentage
 *                                  and a review count
 *   rating_option_vote_aggregated  one row per RATING DIMENSION per product:
 *                                  vote count and value sum
 *
 * Figma's panel needs the distribution — 843 people said 5, 351 said 4 — and
 * no core table, API or block holds it. So this is one grouped read of
 * `rating_option_vote`, which is where the individual votes live.
 *
 * It is a resource model rather than a collection because the answer is five
 * integers, not a set of entities, and there is nothing to hydrate.
 *
 * ===========================================================================
 * WHAT IT COUNTS, EXACTLY
 * ===========================================================================
 * VOTES, not reviews — and on a store with more than one rating dimension
 * (Magento allows "Quality", "Value", "Price" side by side) one review casts
 * one vote per dimension, so it appears in more than one bucket.
 *
 * That is deliberate, and it is the only self-consistent choice: the bars, the
 * `تقييمات` count beside the average and the average itself are all derived
 * from this one result set, so they always add up to each other. The
 * alternative — counting distinct reviews per bucket — makes the bars sum to
 * more than the review count as soon as a review splits its votes across two
 * values, and then the panel contradicts itself.
 *
 * This store runs a single rating dimension today, where the two are identical.
 *
 * ===========================================================================
 * APPROVED, AND FOR THIS STORE VIEW
 * ===========================================================================
 * `review.status_id = APPROVED` and a join through `review_store`, because a
 * review is published per store view. Without the second condition an Arabic
 * shopper would be shown the distribution of reviews written on a store view
 * they cannot read, and a pending or rejected review would count toward the
 * average — which is the one number a shopper reads before buying.
 *
 * ===========================================================================
 * PARAMETERS ARE BOUND, NEVER INTERPOLATED
 * ===========================================================================
 * The product id and store id arrive from the request on a public page.
 * `Select::where()` with a `?` placeholder is the only form used here
 * (CLAUDE.md section 17).
 */
class RatingHistogram
{
    /**
     * The five buckets Figma draws, high to low (535:10098 → 535:10118).
     */
    public const VALUES = [5, 4, 3, 2, 1];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Star value => number of votes, always all five keys, high to low.
     *
     * Every value is present even at zero, because Figma draws five bars
     * whatever the data says — a product with no 2-star votes still has a
     * 2-star row, at zero. Building the array from self::VALUES rather than
     * from the result set is what guarantees that, and it also fixes the ORDER
     * regardless of what the database returns.
     *
     * @return array<int, int>
     */
    public function getVotes(int $productId, int $storeId): array
    {
        $buckets = array_fill_keys(self::VALUES, 0);

        if ($productId <= 0) {
            return $buckets;
        }

        try {
            $connection = $this->resource->getConnection();

            $select = $connection->select()
                ->from(['vote' => $this->resource->getTableName('rating_option_vote')], [])
                ->join(
                    ['review' => $this->resource->getTableName('review')],
                    'review.review_id = vote.review_id',
                    []
                )
                ->join(
                    ['store' => $this->resource->getTableName('review_store')],
                    'store.review_id = review.review_id',
                    []
                )
                ->columns([
                    'value' => 'vote.value',
                    'votes' => new \Zend_Db_Expr('COUNT(*)'),
                ])
                ->where('vote.entity_pk_value = ?', $productId)
                ->where('review.status_id = ?', Review::STATUS_APPROVED)
                ->where('store.store_id = ?', $storeId)
                ->where('vote.value IN (?)', self::VALUES)
                ->group('vote.value');

            foreach ($connection->fetchPairs($select) as $value => $votes) {
                $value = (int) $value;

                if (array_key_exists($value, $buckets)) {
                    $buckets[$value] = (int) $votes;
                }
            }
        } catch (\Exception $e) {
            /**
             * A product page must not 500 because an aggregate could not be
             * read. All-zero buckets render the panel's own empty state, which
             * is honest — it says nobody has rated this yet, and the fault is
             * in the log with the product id rather than swallowed silently
             * (CLAUDE.md section 9).
             */
            $this->logger->error('Spartrak Review: the rating histogram could not be read.', [
                'product_id' => $productId,
                'store_id' => $storeId,
                'exception' => $e,
            ]);
        }

        return $buckets;
    }

    /**
     * Approved reviews for this product on this store view.
     *
     * A SECOND query, and not `array_sum(getVotes())`, because they answer
     * different questions and Figma prints both side by side:
     *
     *     2,404 مراجعات    reviews — somebody wrote something
     *     1,405 تقييمات    ratings — somebody awarded stars
     *
     * A review can carry no rating at all when the store does not require one,
     * which is why the mock's review count is the larger of the two. Deriving
     * either from the other would make the panel state something it does not
     * know.
     */
    public function getReviewCount(int $productId, int $storeId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        try {
            $connection = $this->resource->getConnection();

            $select = $connection->select()
                ->from(['review' => $this->resource->getTableName('review')], [])
                ->join(
                    ['store' => $this->resource->getTableName('review_store')],
                    'store.review_id = review.review_id',
                    []
                )
                ->columns(['reviews' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('review.entity_pk_value = ?', $productId)
                ->where('review.status_id = ?', Review::STATUS_APPROVED)
                ->where('store.store_id = ?', $storeId);

            return (int) $connection->fetchOne($select);
        } catch (\Exception $e) {
            $this->logger->error('Spartrak Review: the review count could not be read.', [
                'product_id' => $productId,
                'store_id' => $storeId,
                'exception' => $e,
            ]);

            return 0;
        }
    }
}
