<?php
/**
 * Spartrak_Review — the PDP reviews panel's data, and the two things Magento's
 * own review flow does not do.
 *
 * The panel itself (Figma 535:10083 desktop / 1204:27098 mobile) and the
 * rating dialog (1207:30485) are TEMPLATES, and they live in the theme with
 * every other Figma-derived view — see
 * app/design/frontend/Spartrak/spartrak/Magento_Review/. What lives here is
 * the part that is not presentation:
 *
 *   Model\ResourceModel\RatingHistogram   the distribution no core table holds
 *   ViewModel\ProductReviews              one read, every number derived from it
 *   Model\RatingVisibility                publishes a product rating to every
 *                                         store view — without it the rating
 *                                         dialog has NO STARS, because Magento
 *                                         seeds ratings and no `rating_store`
 *                                         rows at all
 *   Plugin\Review\SupplyFieldsFigmaDoesNotCollect
 *                                         nickname + title for a form that
 *                                         Figma does not ask them on
 *   Plugin\Review\EnsureRatingIsAvailable the dialog never renders without
 *                                         stars, whether or not anyone ran
 *                                         setup:upgrade
 *   Plugin\Review\PublishReviewToEveryStoreView
 *                                         a review written in Arabic is visible
 *                                         on the English store view too
 *   Plugin\Review\InvalidateProductPageCache
 *                                         so an approved review actually
 *                                         appears on a full-page-cached PDP
 *   Observer\PublishRatingToNewStoreView  the same rating rule, for a store
 *                                         view created after install
 *
 * See README.md for the decisions behind each.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Spartrak_Review',
    __DIR__
);
