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
 *   Plugin\Review\SupplyFieldsFigmaDoesNotCollect
 *                                         nickname + title for a form that
 *                                         Figma does not ask them on
 *   Plugin\Review\InvalidateProductPageCache
 *                                         so an approved review actually
 *                                         appears on a full-page-cached PDP
 *
 * See README.md for the decisions behind each.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Spartrak_Review',
    __DIR__
);
