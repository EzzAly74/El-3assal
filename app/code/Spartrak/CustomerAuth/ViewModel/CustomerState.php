<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\ViewModel;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Is there a signed-in customer? Answered server-side, and safe to call from a
 * FULL-PAGE-CACHED template.
 *
 * ===========================================================================
 * WHY THIS EXISTS — the header was showing "sign in" to signed-in shoppers
 * ===========================================================================
 * The account chip and the mobile profile row used to decide guest-vs-signed-in
 * purely client-side, from the `customer` section of Magento's customerData.
 * On the live site that section was never populated at all
 * (`JSON.parse(localStorage.getItem('mage-cache-storage')).customer` returned
 * `undefined`), so the chip rendered the guest state over a real session.
 *
 * Traced to a genuine bug in this module — etc/frontend/sections.xml keyed its
 * actions on the ROUTE ID (`spartrak_auth/...`) where Magento matches the URL
 * PATH (`phone-auth/...`), so signing in invalidated nothing. That is fixed.
 *
 * But relying on customerData for the STATE was fragile even when it worked:
 * Magento only ever re-fetches a section that is already listed in the
 * `section_data_ids` cookie (see customer-data.js::getExpiredSectionNames —
 * it iterates the cookie, not the section registry), so a section that has
 * never been fetched once can stay unfetched indefinitely. A header that
 * greets the wrong person is not something to leave resting on that.
 *
 * ===========================================================================
 * WHY READING HttpContext IS CACHE-SAFE (and Session would not be)
 * ===========================================================================
 * Magento\Framework\App\Http\Context is the *same* object the full-page cache
 * builds its cache key from — `customer_logged_in` is one of its vary
 * dimensions. So FPC already stores a separate page variant for logged-in and
 * guest visitors, and reading this value cannot leak one visitor's state into
 * the other's cached page: the two are different cache entries by
 * construction. This is the mechanism Magento's own
 * Customer\Block\Account\AuthorizationLink relies on.
 *
 * Injecting Magento\Customer\Model\Session instead WOULD be unsafe here: the
 * session is not part of the cache key, so the first visitor to warm the cache
 * would have their state baked into the page served to everyone else.
 *
 * WHAT IS STILL CLIENT-SIDE, deliberately: the customer's NAME. It is not a
 * cache-vary dimension — varying FPC per customer name would give every
 * shopper their own copy of every page — so the name stays in customerData and
 * the templates fill it in progressively. The STATE (which chip to show) is
 * what had to be server-side, and now is.
 */
class CustomerState implements ArgumentInterface
{
    private HttpContext $httpContext;

    public function __construct(HttpContext $httpContext)
    {
        $this->httpContext = $httpContext;
    }

    /**
     * True when the request is being made by a signed-in customer.
     */
    public function isLoggedIn(): bool
    {
        return (bool) $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH);
    }
}
