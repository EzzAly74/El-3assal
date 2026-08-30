<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Where the auth modal is allowed to send a shopper after a successful sign-in.
 *
 * ===========================================================================
 * WHY AN ALLOWLIST AND NOT A URL IN THE FRAGMENT
 * ===========================================================================
 * The obvious shape for "sign in, then go back to checkout" is to put the
 * destination in the URL: `#auth=login&next=/checkout/`. That is an OPEN
 * REDIRECT. Anyone can send a shopper a link to this store carrying
 * `next=https://not-this-store.example/login`, the shopper signs in on a page
 * they trust, and lands on a convincing copy of it. It is one of the oldest
 * phishing primitives there is, and adding it to a checkout would be adding it
 * to the one page where shoppers are already primed to type card details.
 *
 * So the fragment carries a KEY, never a URL. This class turns a key into an
 * URL that this store built, and a key it does not recognise resolves to
 * nothing at all — the modal then falls back to its existing behaviour of
 * reloading the page the shopper was already on, which is always safe.
 *
 * Adding a destination is a line here plus a key at the call site. That is
 * deliberate friction: every destination is reviewed once, in one place.
 *
 * ===========================================================================
 * FPC-SAFE
 * ===========================================================================
 * Every URL here is store-scoped and shopper-independent, so it renders into
 * cached HTML without varying by customer — the same property AuthConfig
 * documents for its own values, and the reason the modal block stays cacheable.
 */
class PostLoginDestinations implements ArgumentInterface
{
    /**
     * The only destination in use today: a guest bounced off /checkout by
     * Spartrak\Checkout\Plugin\PromptLoginForGuest, who should land back there
     * once signed in rather than on the cart they came from.
     */
    public const CHECKOUT = 'checkout';

    /** key => route path */
    private const ROUTES = [
        self::CHECKOUT => 'checkout',
    ];

    public function __construct(
        private readonly UrlInterface $url
    ) {
    }

    /**
     * key => absolute URL, for the widget's `destinations` option.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $destinations = [];

        foreach (self::ROUTES as $key => $route) {
            $destinations[$key] = $this->url->getUrl($route, ['_secure' => true]);
        }

        return $destinations;
    }
}
