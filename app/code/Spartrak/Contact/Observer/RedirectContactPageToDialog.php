<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Contact\Observer;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;

/**
 * Takes Magento's contact FORM PAGE out of the storefront, sending the shopper
 * back where they were with the Spartrak contact dialog open instead.
 *
 * Covers `/contact/` and `/contact/index/index/` alike: both resolve to the
 * same `contact_index_index` full action name, so one observer registration
 * serves the two URLs.
 *
 * ===========================================================================
 * WHY AN OBSERVER AND NOT A ROUTER OR A CONTROLLER OVERRIDE
 * ===========================================================================
 * The same reasoning, and the same verified interception point, as
 * Spartrak\CustomerAuth\Observer\RedirectNativeAuthPageToModal — see that class
 * for the full note. In short: setting FLAG_NO_DISPATCH during
 * `controller_action_predispatch_*` is the framework's own supported way to
 * stop an action running (2.4.8, vendor/magento/framework/App/FrontController.php),
 * so the controller never executes while the route, and every link Magento
 * itself generates to it, stays intact.
 *
 * ===========================================================================
 * WHAT IS DELIBERATELY LEFT ALONE
 * ===========================================================================
 * `contact/index/post` is NOT touched. It is the POST endpoint behind the form,
 * it still works, and Magento's own Contact configuration (recipient address,
 * sender identity) is untouched — this changes which UI the storefront shows,
 * not whether the platform can receive a contact message. The design replaces
 * the form with direct channels; the endpoint is left standing so that
 * re-introducing a form later is a template change and not a module change.
 *
 * NOTE ON THE HEADER AND FOOTER LINKS. Both still point at the real
 * `contact/index/index` URL and are progressively enhanced into dialog
 * triggers by js/spartrak-dialog.js, so a shopper with JavaScript off follows
 * the link, lands here, and is redirected to a page whose dialog they cannot
 * open. That is why the contact details are ALSO rendered in the footer, where
 * they are plain server-side markup — see the footer template. This observer
 * is therefore never the only route to the information.
 */
class RedirectContactPageToDialog implements ObserverInterface
{
    /**
     * The fragment js/spartrak-dialog.js reads on init to decide which dialog
     * to open. Kept in step with the `data-spartrak-dialog` id the contact
     * template declares.
     */
    private const DIALOG_FRAGMENT = '#dialog=contact';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly ActionFlag $actionFlag,
        private readonly RedirectInterface $redirect,
        private readonly UrlInterface $url
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->request instanceof HttpRequest || !$this->request->isGet()) {
            return;
        }

        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->setRedirect($this->resolveSafeReturnUrl() . self::DIALOG_FRAGMENT);
    }

    /**
     * The referring page when it is genuinely ours, else the store home page.
     *
     * The fragment rather than a query parameter, for the reason recorded on
     * RedirectNativeAuthPageToModal: fragments are never sent to the server, so
     * this lands on exactly the same full-page-cache entry the shopper already
     * had, where `?contact=1` would fork a second cache entry for every page
     * anyone ever reached this way.
     *
     * The base-URL check is not decoration. `Referer` is attacker-controlled,
     * so feeding it into setRedirect() unchecked turns this observer into an
     * open redirect: a link to /contact/ from an external page would bounce the
     * shopper straight back off-site, from a hop that appears to originate on
     * this domain.
     */
    private function resolveSafeReturnUrl(): string
    {
        $baseUrl = $this->url->getBaseUrl();
        $referer = (string) $this->redirect->getRefererUrl();

        // `str_contains('contact/')` guards the one loop this can have: arriving
        // at /contact/ FROM /contact/ would redirect back to itself, which this
        // same observer intercepts again.
        if ($referer !== '' && str_starts_with($referer, $baseUrl) && !str_contains($referer, 'contact/')) {
            return $referer;
        }

        return $baseUrl;
    }
}
