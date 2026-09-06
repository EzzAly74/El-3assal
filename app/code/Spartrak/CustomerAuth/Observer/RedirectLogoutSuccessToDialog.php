<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Observer;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;

/**
 * Stops Magento's "You are signed out" PAGE from being part of the storefront
 * UX, sending the shopper to the store home page with the Spartrak signed-out
 * dialog open instead (Figma 1280:27572).
 *
 * ===========================================================================
 * WHY AN OBSERVER AND NOT A ROUTER OR A CONTROLLER OVERRIDE
 * ===========================================================================
 * The same verified interception point as the sibling
 * RedirectNativeAuthPageToModal in this directory — setting FLAG_NO_DISPATCH
 * during `controller_action_predispatch_*` is the framework's own supported
 * way to stop an action running (2.4.8,
 * vendor/magento/framework/App/FrontController.php), so the controller never
 * executes while the route, and the relative `logoutSuccess` path that
 * `Logout::execute()` redirects to, stay intact. See that class for the full
 * argument.
 *
 * ===========================================================================
 * WHAT THE PAGE THIS REPLACES ACTUALLY DID
 * ===========================================================================
 * Checked rather than assumed, because dropping a page that clears session
 * state would be a real bug. `Magento_Customer::logout.phtml` renders exactly
 * two things: one sentence, and `Magento_Customer/js/logout-redirect`, whose
 * whole job is to bounce the shopper to the home page after five seconds.
 * This redirect IS that bounce, minus the five seconds.
 *
 * Nothing about signing out happens on that page. `Logout::execute()` has
 * already called `$this->session->logout()` and deleted the `mage-cache-sessid`
 * cookie before it redirects here, and it is the ABSENCE of that cookie that
 * makes `Magento_Customer/js/customer-data` invalidate every private-content
 * section on the next page load — which is the home page either way. So the
 * minicart, wishlist counter and account chip refresh exactly as before.
 *
 * ===========================================================================
 * WHY THE HOME PAGE AND NOT THE REFERRER
 * ===========================================================================
 * The sibling observer returns the shopper to where they were. That is wrong
 * here: the page they were on when they signed out is, very often, a page that
 * requires being signed in — the dashboard, an order, the address book. Sending
 * them back would land them on a customer route that Magento immediately
 * redirects to the login page, which the sibling observer then turns into the
 * auth modal. The shopper would have asked to sign out and been shown a sign-in
 * form.
 *
 * The home page is also what the page being replaced already redirected to, so
 * this changes the timing and the chrome, not the destination.
 */
class RedirectLogoutSuccessToDialog implements ObserverInterface
{
    /**
     * The fragment js/spartrak-dialog.js reads on init to decide which dialog
     * to open. Kept in step with the `spartrakDialog` id the template declares.
     *
     * A FRAGMENT and not a query parameter: fragments are never sent to the
     * server, so this lands on exactly the same full-page-cache entry as a
     * plain visit to the home page, where `?dialog=signed-out` would fork a
     * second cache entry of the busiest page on the site. The widget also
     * strips it from the URL once it has been read, so a reload or a back
     * navigation does not announce the sign-out a second time.
     */
    private const DIALOG_FRAGMENT = '#dialog=signed-out';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
        private readonly UrlInterface $url
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->request instanceof HttpRequest || !$this->request->isGet()) {
            return;
        }

        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->setRedirect($this->url->getBaseUrl() . self::DIALOG_FRAGMENT);
    }
}
