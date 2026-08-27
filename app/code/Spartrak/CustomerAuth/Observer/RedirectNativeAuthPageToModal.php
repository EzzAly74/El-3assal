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
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * Stops Magento's stock login / create / forgot-password PAGES from being part
 * of the storefront UX, sending the shopper back where they were with the
 * Spartrak auth modal opened at the matching step instead.
 *
 * ===========================================================================
 * WHY AN OBSERVER AND NOT A ROUTER OR A CONTROLLER OVERRIDE
 * ===========================================================================
 * Setting FLAG_NO_DISPATCH during `controller_action_predispatch_*` is the
 * framework's own supported interception point — verified in 2.4.8 at
 * vendor/magento/framework/App/FrontController.php:239-241, where
 * getActionResponse() returns the response untouched instead of running the
 * action when that flag is set. So the controller never executes, yet the route
 * still exists and Magento's own machinery is intact.
 *
 * The alternatives were all worse. Rewriting routes hides the endpoints Magento
 * itself links to and generates. Overriding the three controllers means
 * duplicating classes we do not own. Removing the layout handles would still
 * render a blank page at a real URL.
 *
 * ===========================================================================
 * WHAT IS DELIBERATELY LEFT ALONE
 * ===========================================================================
 * GET pages only. `loginPost`, `createPost`, `forgotpasswordpost` and
 * `resetpassword` are NOT touched: they are POST/back-channel actions that
 * Magento and third-party code still legitimately use, and the requirement is
 * specifically that the GET pages must not surface as standalone screens.
 *
 * `resetpassword` (the emailed reset link) is also intentionally excluded — it
 * arrives with a token in the query string and has no modal equivalent, so
 * breaking it would strand anyone completing an email-based reset.
 */
class RedirectNativeAuthPageToModal implements ObserverInterface
{
    /**
     * Maps the intercepted action to the modal step that replaces it. The values
     * are the same `data-auth-step` ids the template declares, so this stays in
     * step with the markup-driven state machine rather than inventing new names.
     */
    private const MODAL_STEP_BY_ACTION = [
        'login' => 'login',
        'create' => 'signup',
        'forgotpassword' => 'reset-phone',
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
        private readonly RedirectInterface $redirect,
        private readonly UrlInterface $url,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->request instanceof HttpRequest || !$this->request->isGet()) {
            return;
        }

        $step = self::MODAL_STEP_BY_ACTION[strtolower((string) $this->request->getActionName())] ?? null;

        if ($step === null) {
            return;
        }

        // A signed-in shopper has no use for any of these. Core already
        // redirects them; matching that behaviour keeps the two consistent.
        $target = $this->customerSession->isLoggedIn()
            ? $this->url->getUrl('customer/account')
            : $this->buildModalTarget($step);

        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->setRedirect($target);
    }

    /**
     * Where the shopper lands, with the modal step carried in the FRAGMENT.
     *
     * A fragment rather than a query parameter, for two concrete reasons:
     *
     *   1. PERFORMANCE. Fragments are never sent to the server, so
     *      `/#auth=login` hits exactly the same full-page-cache entry as `/`.
     *      A `?auth=login` query string would fork a second cache entry for
     *      every page anyone ever reached this way.
     *   2. It leaves no state in a URL the shopper might bookmark or share.
     *
     * spartrakAuth reads location.hash on init and opens the matching step.
     */
    private function buildModalTarget(string $step): string
    {
        return $this->resolveSafeReturnUrl() . '#auth=' . $step;
    }

    /**
     * The referring page when it is genuinely ours, else the store home page.
     *
     * The base-URL check is NOT decoration. `Referer` is attacker-controlled, so
     * feeding it into setRedirect() unchecked turns this observer into an open
     * redirect: a link to /customer/account/login from an external page would
     * bounce the shopper straight back off-site, which is a ready-made phishing
     * hop that appears to originate from this domain.
     */
    private function resolveSafeReturnUrl(): string
    {
        $baseUrl = $this->url->getBaseUrl();
        $referer = (string) $this->redirect->getRefererUrl();

        if ($referer !== '' && str_starts_with($referer, $baseUrl) && !$this->isAuthPageUrl($referer)) {
            return $referer;
        }

        return $baseUrl;
    }

    /**
     * Guards against bouncing between auth pages.
     *
     * Without this, arriving at /customer/account/create FROM
     * /customer/account/login would redirect back to the login page, which this
     * same observer then intercepts again — a redirect loop the shopper
     * experiences as the browser giving up.
     */
    private function isAuthPageUrl(string $url): bool
    {
        foreach (array_keys(self::MODAL_STEP_BY_ACTION) as $action) {
            if (str_contains($url, 'customer/account/' . $action)) {
                return true;
            }
        }

        return false;
    }
}
