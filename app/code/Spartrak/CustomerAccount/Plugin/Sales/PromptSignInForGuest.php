<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Plugin\Sales;

use Closure;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;
use Magento\Sales\Controller\OrderInterface;
use Spartrak\CustomerAuth\ViewModel\PostLoginDestinations;

/**
 * A shopper reaching their own order area must be signed in — and if they are
 * not, they get the sign-in modal and then their orders, not a dead end.
 *
 * ===========================================================================
 * THE HOLE THIS CLOSES
 * ===========================================================================
 * Magento_Sales declares `Magento\Sales\Controller\OrderInterface` and marks it
 * @api for exactly one purpose: to name the controllers that serve ONE
 * customer's own orders — history, view, reorder, invoice, shipment, credit
 * memo and the four print actions. Every guest-facing equivalent lives under
 * `Magento\Sales\Controller\Guest\*` and does NOT implement it, which is what
 * makes this interface a safe seam.
 *
 * What Magento_Sales does not ship in 2.4.8 is a plugin binding it to an
 * authentication check. Magento_Customer guards its own area
 * (`Magento\Customer\Controller\AccountInterface`, via
 * Magento\Customer\Controller\Plugin\Account in its frontend di.xml) and no
 * module does the same for this one — verified across every frontend di.xml
 * under vendor/magento in this install.
 *
 * The visible consequence: a signed-out visitor at /sales/order/history is not
 * turned away. `Magento\Sales\Block\Order\History` filters the collection on a
 * null customer id, so they are served a rendered, indexable "you have no
 * orders" page — a wrong answer rather than a request to sign in.
 *
 * ===========================================================================
 * AND IT SENDS THEM TO THEIR ORDERS AFTERWARDS
 * ===========================================================================
 * The redirect carries `#auth=login&next=orders`, the same contract
 * Spartrak\Checkout\Plugin\PromptLoginForGuest uses to return a shopper to
 * checkout. `next` is a KEY resolved against the allowlist in
 * Spartrak\CustomerAuth\ViewModel\PostLoginDestinations, never a URL — see that
 * class for why that boundary exists and why the key resolves to the order LIST
 * even when the intercepted route named a specific order.
 *
 * Core's own idiom here would be `$this->session->authenticate()`, which sets
 * `beforeAuthUrl` and redirects to /customer/account/login. It is not used,
 * because on this storefront that page does not exist as a screen —
 * Spartrak\CustomerAuth\Observer\RedirectNativeAuthPageToModal intercepts it —
 * so authenticate() would cost a second redirect to arrive at the same modal,
 * and `beforeAuthUrl` would be dropped on the floor: the modal reloads in place
 * on success and never reads it. One hop, carrying the destination the modal
 * does read, is both faster and the only version that actually returns the
 * shopper to their orders.
 *
 * Deliberately NO message. The modal is the message; a toast behind it would
 * say the same thing twice.
 *
 * ===========================================================================
 * WHY A FRAGMENT, AND WHY THE REFERER IS SAFE
 * ===========================================================================
 * FRAGMENT, not query string: fragments are never sent to the server, so the
 * shopper lands on exactly the full-page-cache entry they would have had
 * anyway. `?auth=login` would fork a second cached copy of every page anyone
 * ever reached this way (CLAUDE.md §4).
 *
 * The referer is not re-validated here because
 * Magento\Store\App\Response\Redirect::_getUrl() already does it: an external
 * referer fails `_isUrlInternal()` and is replaced with the store base URL
 * before this class ever sees it. Adding a second base-URL test would be
 * restating the platform's guarantee, not strengthening it.
 *
 * The one check this DOES make is the loop guard: a referer inside the very
 * area being guarded would be intercepted again on arrival, which the shopper
 * experiences as the browser giving up. Same failure the auth-page observer
 * guards against, for the same reason.
 */
class PromptSignInForGuest
{
    /**
     * The auth widget's open-on-load contract: `#auth=<step>&next=<key>`, read
     * by js/spartrak-auth.js::_openFromLocationHash. `login` is the widget's
     * first step id, declared as `data-auth-step` in login-modal.phtml.
     */
    private const AUTH_FRAGMENT = '#auth=login&next=' . PostLoginDestinations::ORDERS;

    /**
     * The URL prefix this plugin guards, used only by the loop guard below.
     * `sales/order/` and not `sales/` — the guest lookup at /sales/guest/* is a
     * perfectly good place to come from and to return to.
     */
    private const GUARDED_PATH = 'sales/order/';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RedirectInterface $redirect,
        private readonly RedirectFactory $redirectFactory,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @param OrderInterface $controllerAction
     * @param Closure $proceed
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface|Redirect|void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(OrderInterface $controllerAction, Closure $proceed)
    {
        if ($this->customerSession->isLoggedIn()) {
            return $proceed();
        }

        return $this->redirectFactory->create()->setUrl($this->resolveReturnUrl() . self::AUTH_FRAGMENT);
    }

    /**
     * The page the shopper came from, or the store home page.
     *
     * Only the page BEHIND the modal — where a shopper who dismisses it without
     * signing in is left. A shopper who does sign in goes to `next` instead, so
     * this is never the destination of the journey.
     */
    private function resolveReturnUrl(): string
    {
        $referer = (string) $this->redirect->getRefererUrl();

        if ($referer === '' || str_contains($referer, self::GUARDED_PATH)) {
            return $this->url->getBaseUrl();
        }

        return $referer;
    }
}
