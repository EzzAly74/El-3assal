<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Plugin;

use Magento\Checkout\Controller\Index\Index;
use Magento\Checkout\Helper\Data as CheckoutHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Spartrak\CustomerAuth\ViewModel\PostLoginDestinations;

/**
 * Guest checkout is disabled on this store, so a signed-out shopper who reaches
 * /checkout has to sign in. Core tells them so with a red toast:
 *
 *     $this->messageManager->addErrorMessage(__('Guest checkout is disabled.'));
 *     return $this->resultRedirectFactory->create()->setPath('checkout/cart');
 *
 * (Magento\Checkout\Controller\Index\Index::execute, lines 35-38.)
 *
 * That is an error message for something the shopper did nothing wrong to
 * trigger, and it names a policy instead of offering the action that resolves
 * it. This replaces it with the sign-in modal the theme already has.
 *
 * ===========================================================================
 * NO NEW MARKUP, NO NEW JAVASCRIPT
 * ===========================================================================
 * `spartrak.auth_modal` is already rendered on every page from the theme's
 * Magento_Theme/layout/default.xml, and its widget already accepts a URL
 * fragment as an open trigger — `_openFromLocationHash()` matches
 * `#auth=<step>` and opens straight onto that step. So the entire fix is
 * choosing the redirect's fragment, and the modal that appears is the same one
 * the header's account control opens. Nothing about the auth flow is
 * duplicated or forked for checkout.
 *
 * The widget also clears the fragment once it has opened, precisely so a reload
 * does not re-trigger it.
 *
 * ===========================================================================
 * AND IT SENDS THEM BACK TO CHECKOUT, NOT TO THE CART
 * ===========================================================================
 * The fragment carries `&next=checkout`. Signing in used to leave the shopper
 * on the cart they had been bounced to, having to press "proceed to checkout" a
 * second time to reach the page they had already asked for — the redirect was
 * undone but the intent was lost.
 *
 * `next` is a KEY, never a URL. The auth widget resolves it against the
 * allowlist in Spartrak\CustomerAuth\ViewModel\PostLoginDestinations and
 * ignores anything it does not recognise. That is a security boundary, not a
 * convenience: a fragment that could name an arbitrary URL would be an open
 * redirect, and this is the one flow where a shopper is most primed to trust
 * whatever page they land on. See that class for the full reasoning.
 *
 * Nothing else about the modal changes. Opened any other way — the header
 * account control, the native-auth-page observer — there is no `next`, the
 * widget's postLoginUrl stays null, and it reloads in place exactly as before.
 *
 * ===========================================================================
 * WHY AN `around` PLUGIN, AND WHY IT RE-STATES CORE'S CONDITIONS
 * ===========================================================================
 * The behaviour being replaced is one branch in the middle of `execute()`.
 * There is no seam at that branch: a `before` plugin cannot return a result in
 * its place, and an `after` plugin sees a redirect to the cart with no way to
 * tell WHICH of the three branches produced it — an empty cart and a rejected
 * guest are the same redirect.
 *
 * So the guard is mirrored, and mirrored in core's own order, so that this only
 * ever fires in the exact case core would have shown that one message:
 *
 *   1. one-page checkout must be ON      — otherwise core's "turned off"
 *                                          message is the correct one and this
 *                                          must not speak over it
 *   2. the quote must be checkout-worthy — an empty or invalid cart redirects
 *                                          to the cart on its own merits, and
 *                                          a sign-in prompt would be a non
 *                                          sequitur
 *   3. the shopper must be a guest and guest checkout disallowed
 *
 * Anything else falls straight through to `$proceed()`, so the plugin is
 * invisible to every other path through the controller.
 */
class PromptLoginForGuest
{
    /**
     * The auth widget's own open-on-load contract: `#auth=<step>`, optionally
     * followed by `&next=<destination key>`.
     *
     * `login` is the widget's first step. `checkout` is a key in
     * PostLoginDestinations, NOT a path — see the class header.
     */
    private const AUTH_FRAGMENT = 'auth=login&next=' . PostLoginDestinations::CHECKOUT;

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CheckoutHelper $checkoutHelper,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @param Index $subject
     * @param callable $proceed
     * @return ResultInterface
     */
    public function aroundExecute(Index $subject, callable $proceed)
    {
        if (!$this->shouldPromptSignIn($subject)) {
            return $proceed();
        }

        // Deliberately NO message. The modal is the message, and a toast behind
        // it would say the same thing twice — once as an error the shopper
        // cannot act on.
        return $this->redirectFactory->create()
            ->setPath('checkout/cart', ['_fragment' => self::AUTH_FRAGMENT]);
    }

    /**
     * True only where core would have said "Guest checkout is disabled."
     */
    private function shouldPromptSignIn(Index $subject): bool
    {
        if ($this->customerSession->isLoggedIn()) {
            return false;
        }

        if (!$this->checkoutHelper->canOnepageCheckout()) {
            return false;
        }

        try {
            $quote = $subject->getOnepage()->getQuote();
        } catch (\Exception $exception) {
            // No quote to reason about — let core own the outcome rather than
            // guessing at one. Swallowing this is safe precisely because the
            // fallback is core's unmodified behaviour, not a silent no-op.
            return false;
        }

        if (!$quote->hasItems() || $quote->getHasError() || !$quote->validateMinimumAmount()) {
            return false;
        }

        return !$this->checkoutHelper->isAllowedGuestCheckout($quote);
    }
}
