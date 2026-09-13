<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Wishlist\Controller\Ajax;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;
use Spartrak\Wishlist\Model\WishlistToggle;

/**
 * Adds or removes one product from the wish list, without leaving the page.
 *
 *     POST /spartrak-wishlist/ajax/toggle   { product: <id>, form_key: ... }
 *
 * ===========================================================================
 * WHY THIS EXISTS RATHER THAN REUSING wishlist/index/add
 * ===========================================================================
 * Core's Add and Remove controllers are built to NAVIGATE. Add returns a
 * redirect and Remove needs a wishlist_item_id the storefront card has no way
 * to know. That is why pressing the heart used to land the shopper on the wish
 * list page: they were not doing something wrong, they were following a link
 * that is meant to be followed.
 *
 * Three further reasons the pair cannot simply be called over AJAX:
 *
 *   1. REMOVE IS ADDRESSED BY ITEM, NOT BY PRODUCT. The card knows a product
 *      id. Mapping one to the other client-side would mean shipping every
 *      wishlist item id to every page.
 *   2. THEY ARE TWO ENDPOINTS FOR ONE CONTROL. See WishlistToggle's note on
 *      why "which of the two did the shopper mean" is a server-side fact.
 *   3. Add's AJAX branch answers with `backUrl`, i.e. "now go here" - the
 *      exact behaviour being removed.
 *
 * Core's own model layer still does all the work; see WishlistToggle.
 *
 * ===========================================================================
 * WHAT THE RESPONSE DELIBERATELY DOES NOT CARRY
 * ===========================================================================
 * Not the new count, and not the message text. Both arrive through
 * customer-data instead - see etc/frontend/sections.xml. The header badge and
 * the toast are then updated by the SAME mechanism that keeps them right after
 * a full page load, so there is exactly one code path for each and no way for
 * an optimistic client-side count to drift from the server's.
 *
 * The body is therefore three fields, and its only job is to tell the browser
 * which heart to fill.
 *
 * ===========================================================================
 * CSRF
 * ===========================================================================
 * No CsrfAwareActionInterface, on purpose: this is a state-changing POST, so
 * Magento's default validation is exactly what it should get. The framework's
 * CsrfValidator therefore requires a valid form key before execute() is ever
 * entered (CLAUDE.md section 17). The browser sends the one from the `form_key`
 * cookie, which Magento_PageCache/js/form-key-provider keeps valid on
 * full-page-cached pages.
 *
 * ===========================================================================
 * A SIGNED-OUT SHOPPER
 * ===========================================================================
 * Answers 200 with `requires_login`, and RAISES NO MESSAGE. A session message
 * here would be drained by the next customer-data fetch and toast at a moment
 * the shopper has no context for it. The browser opens the Spartrak auth modal
 * instead, which is the actual next step.
 */
class Toggle implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly WishlistToggle $wishlistToggle,
        private readonly MessageManager $messageManager,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $productId = (int) $this->request->getParam('product');

        if ($productId <= 0) {
            return $result->setData([
                'ok' => false,
                'message' => (string) __('We can\'t specify a product.'),
            ]);
        }

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData([
                'ok' => false,
                'requires_login' => true,
                'product_id' => $productId,
            ]);
        }

        try {
            $toggled = $this->wishlistToggle->toggle($productId);
        } catch (LocalizedException $e) {
            return $result->setData([
                'ok' => false,
                'product_id' => $productId,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            // Logged rather than swallowed (CLAUDE.md section 9), and the
            // shopper is told something generic rather than shown an internal
            // failure.
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            return $result->setData([
                'ok' => false,
                'product_id' => $productId,
                'message' => (string) __('We can\'t update your wish list right now.'),
            ]);
        }

        $this->raiseMessage($toggled->added, $toggled->productName);

        return $result->setData([
            'ok' => true,
            'added' => $toggled->added,
            'product_id' => $toggled->productId,
        ]);
    }

    /**
     * Raises the toast through Magento's own COMPLEX-MESSAGE renderers, using
     * the two codes core already registers for these exact events
     * (Magento_Wishlist/etc/frontend/di.xml).
     *
     * That is what lets this theme's overrides of
     * Magento_Wishlist::messages/addProductSuccessMessage.phtml and
     * removeWishlistItemSuccessMessage.phtml emit Figma's title/body/action
     * structure instead of one long sentence - and it means a press from HERE
     * and a press from core's own controller produce the identical toast,
     * rather than two that have to be kept in step.
     *
     * `referer` is the wish list itself, not the page the shopper came from:
     * with the press no longer navigating anywhere, "click here to continue
     * shopping" describes nothing, while "view your wish list" is the one link
     * that is now genuinely useful.
     */
    private function raiseMessage(bool $added, string $productName): void
    {
        if ($added) {
            $this->messageManager->addComplexSuccessMessage(
                'addProductSuccessMessage',
                [
                    'product_name' => $productName,
                    'referer' => $this->url->getUrl('wishlist'),
                ]
            );

            return;
        }

        $this->messageManager->addComplexSuccessMessage(
            'removeWishlistItemSuccessMessage',
            ['product_name' => $productName]
        );
    }
}
