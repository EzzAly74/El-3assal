<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Controller\WishlistProviderInterface;
use Magento\Wishlist\Helper\Data as WishlistHelper;
use Magento\Wishlist\Model\ItemFactory;
use Magento\Wishlist\Model\ResourceModel\Item as ItemResource;

/**
 * Adds a product to the customer's wish list, or takes it off - whichever the
 * product is not already.
 *
 * ===========================================================================
 * WHY A TOGGLE AT ALL, AND WHY IT LIVES HERE RATHER THAN IN THE CONTROLLER
 * ===========================================================================
 * The card's heart is ONE control with two states, so "add" and "remove" are
 * not two features - they are the same press, and which one it means is a fact
 * the SERVER holds, not the browser. Splitting it into two endpoints would put
 * that decision on the client, where it can be stale: a shopper with the
 * homepage open in two tabs, or one whose customer-data cache has expired,
 * would send "add" for something already on the list.
 *
 * Deciding it here also makes the operation idempotent in the way that
 * matters: whatever the client believed, the outcome is a list that either
 * contains the product or does not, and the response says which.
 *
 * In a model and not in the controller because the controller's job is HTTP -
 * reading a parameter, choosing a status code - and none of the reasoning
 * below is about HTTP. It is also what makes this reachable from anywhere else
 * that ever needs it (the PDP's own heart, a GraphQL resolver) without
 * duplicating a line.
 *
 * ===========================================================================
 * WHAT IT DELIBERATELY DOES NOT DO
 * ===========================================================================
 * It does not touch the message manager and it does not render anything. The
 * controller raises the message, because WHICH message a press deserves is a
 * presentation decision and because a future non-HTTP caller must not have a
 * toast raised behind its back.
 */
class WishlistToggle
{
    public function __construct(
        private readonly WishlistProviderInterface $wishlistProvider,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ItemFactory $itemFactory,
        private readonly ItemResource $itemResource,
        private readonly ResourceConnection $resource,
        private readonly WishlistHelper $wishlistHelper,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @throws LocalizedException when the product cannot be wish-listed, or
     *         when the customer has no wish list (i.e. is not signed in).
     */
    public function toggle(int $productId): ToggleResult
    {
        $wishlist = $this->wishlistProvider->getWishlist();

        if (!$wishlist || !$wishlist->getId() && !$wishlist->getCustomerId()) {
            // The provider returns false for a visitor with no wish list of
            // their own. Treated as a domain error rather than a 404 so the
            // controller can answer "sign in first" - which is the only thing
            // a shopper can actually do about it.
            throw new LocalizedException(__('Please sign in to use your wish list.'));
        }

        $product = $this->loadProduct($productId);

        $itemId = $this->findItemId((int) $wishlist->getId(), $productId);

        if ($itemId !== null) {
            return $this->remove($wishlist, $itemId, $productId, (string) $product->getName());
        }

        return $this->add($wishlist, $product, $productId);
    }

    /**
     * @throws LocalizedException
     */
    private function loadProduct(int $productId): \Magento\Catalog\Api\Data\ProductInterface
    {
        try {
            // Store-scoped, so the name in the toast is the one the shopper is
            // reading on the card and not the default-store value.
            $product = $this->productRepository->getById(
                $productId,
                false,
                (int) $this->storeManager->getStore()->getId()
            );
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('We can\'t specify a product.'));
        }

        // The same gate core's own Add controller applies. Without it, a
        // handcrafted POST could put a disabled or not-visible product on a
        // wish list, where it would then render as a broken row.
        if (!$product->isVisibleInCatalog() || !$product->isVisibleInSiteVisibility()) {
            throw new LocalizedException(__('We can\'t specify a product.'));
        }

        return $product;
    }

    /**
     * The wishlist_item_id for this product on this list, or null.
     *
     * ===================================================================
     * WHY THE TABLE AND NOT THE ITEM COLLECTION
     * ===================================================================
     * Magento\Wishlist\Model\ResourceModel\Item\Collection::_afterLoad()
     * calls _assignProducts(), which loads a whole product collection with
     * its attributes and price data. This method needs ONE INTEGER, and on a
     * page with twenty cards it is the query a shopper waits on. Reading two
     * indexed columns is the honest shape of the question.
     *
     * It stays a read - every WRITE below goes through the Item model, so the
     * resource layer's own hooks and any third-party plugins on them still
     * run. That is the line: reads that need no model skip it, mutations
     * never do.
     */
    private function findItemId(int $wishlistId, int $productId): ?int
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName('wishlist_item'), ['wishlist_item_id'])
            ->where('wishlist_id = ?', $wishlistId)
            ->where('product_id = ?', $productId)
            ->limit(1);

        $itemId = $connection->fetchOne($select);

        return $itemId === false || $itemId === null || $itemId === '' ? null : (int) $itemId;
    }

    /**
     * @throws LocalizedException
     */
    private function add(
        \Magento\Wishlist\Model\Wishlist $wishlist,
        \Magento\Catalog\Api\Data\ProductInterface $product,
        int $productId
    ): ToggleResult {
        $item = $wishlist->addNewItem($product);

        // addNewItem() signals a refusal by RETURNING A STRING rather than
        // throwing - a configurable product with no selection, for instance.
        // Core checks for this too; without the check the string would be
        // handed to the event below as though it were an item.
        if (is_string($item)) {
            throw new LocalizedException(__($item));
        }

        if ($wishlist->isObjectNew()) {
            $wishlist->save();
        }

        // The same event core's Add controller dispatches, with the same
        // payload, so anything already listening (reports, a CRM sync) keeps
        // working when a press comes through this endpoint instead.
        $this->eventManager->dispatch(
            'wishlist_add_product',
            ['wishlist' => $wishlist, 'product' => $product, 'item' => $item]
        );

        $this->wishlistHelper->calculate();

        return new ToggleResult($productId, true, (string) $product->getName());
    }

    /**
     * @throws LocalizedException
     */
    private function remove(
        \Magento\Wishlist\Model\Wishlist $wishlist,
        int $itemId,
        int $productId,
        string $productName
    ): ToggleResult {
        $item = $this->itemFactory->create();
        $this->itemResource->load($item, $itemId);

        // Re-checked after the load: findItemId() and this are two round
        // trips, and a concurrent request on the wish-list page could have
        // deleted the row between them. Silently reporting "removed" is the
        // right answer there - the product is off the list either way - but
        // calling delete() on an empty model is not.
        if ((int) $item->getId() !== $itemId) {
            return new ToggleResult($productId, false, $productName);
        }

        $this->itemResource->delete($item);
        $wishlist->save();
        $this->wishlistHelper->calculate();

        return new ToggleResult($productId, false, $productName);
    }
}
