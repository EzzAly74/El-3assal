<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Plugin\Review;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Model\AbstractModel;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Psr\Log\LoggerInterface;

/**
 * Drops the cached product page when a review changes what it says.
 *
 * ===========================================================================
 * THE DEFECT THIS FIXES, AND WHY IT IS NOT COSMETIC
 * ===========================================================================
 * The Spartrak reviews panel is rendered SERVER-SIDE — the average, the two
 * counts and the five bars all come from ViewModel\ProductReviews at render
 * time. That is a deliberate performance choice: Magento's own review.phtml
 * fetches its list over AJAX after paint, and this panel does not, so the
 * distribution is in the HTML with no second request (CLAUDE.md section 13).
 *
 * The cost of that choice is that the numbers are baked into the full page
 * cache, and NOTHING IN MAGENTO INVALIDATES A PDP WHEN A REVIEW IS APPROVED.
 * Checked, not assumed: `Magento\Review\Model\Review` implements no
 * IdentityInterface, so `Framework\App\Cache\FlushCacheByTags` (which is
 * plugged onto EAV entities only) never sees it, and module-review's own
 * events.xml carries a single observer, for product DELETION.
 *
 * Left alone, the consequence is that a moderator approves a review, the
 * shopper who wrote it reloads the page, and their review has changed nothing:
 * same average, same counts, same bars — until something unrelated flushes the
 * page. On a store where reviews are moderated by hand, that is the normal
 * case rather than an edge one.
 *
 * ===========================================================================
 * `clean_cache_by_tags`, NOT A DIRECT CACHE CALL
 * ===========================================================================
 * The event is the platform's own targeted-purge channel, and it has two
 * listeners that both matter here:
 *
 *     Magento_PageCache        cleans the built-in full page cache
 *     Magento_CacheInvalidate  sends a PURGE to Varnish / Fastly
 *
 * Calling a cache pool directly would satisfy the first and silently skip the
 * second, so a store behind Varnish — which is how this storefront is meant to
 * run — would keep serving the stale page from the edge. Dispatching the event
 * means whichever cache layers are configured are the ones that get purged,
 * and this class does not need to know which those are.
 *
 * ===========================================================================
 * ONE TAG, NOT A FULL FLUSH
 * ===========================================================================
 * `cat_p_<id>` — the identity Catalog\Model\Product publishes and the tag every
 * PDP is stored under. `TypeListInterface::invalidate('full_page')` was the
 * obvious alternative and is the wrong tool by three orders of magnitude: one
 * shopper's review would empty the cache for the whole catalogue, and on a
 * store with 8,908 SKUs that is a self-inflicted traffic spike every time
 * somebody clicks a star.
 */
class InvalidateProductPageCache
{
    public function __construct(
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ReviewResource $subject
     * @param null $result
     * @param AbstractModel $object
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterAggregate(ReviewResource $subject, $result, $object = null): void
    {
        if (!$object instanceof AbstractModel) {
            return;
        }

        /**
         * `entity_pk_value` is the reviewed product's id. Core's own
         * aggregate() loads the review first when the column is empty, so by
         * the time this runs it is populated for every real review — and when
         * it genuinely is not there is nothing to purge and no page to fix.
         */
        $productId = (int) $object->getData('entity_pk_value');

        if ($productId <= 0) {
            return;
        }

        try {
            $this->cacheContext->registerEntities(Product::CACHE_TAG, [$productId]);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
        } catch (\Exception $e) {
            /**
             * A failed purge must not turn a successful review into an error
             * page — the shopper's review IS saved by this point, and core has
             * already told them so. It is logged with the product id, because
             * the visible symptom (a stale panel) gives no clue where to look.
             */
            $this->logger->error('Spartrak Review: the product page cache could not be invalidated.', [
                'product_id' => $productId,
                'exception' => $e,
            ]);
        }
    }
}
