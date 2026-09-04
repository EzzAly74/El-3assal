<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Controller\Address;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;

/**
 * GET /spartrak_account/address/form[?id=<n>]
 *
 * The address form, on its own, as HTML — for the dialog the address book opens
 * instead of navigating away (Figma 557:5173).
 *
 * ===========================================================================
 * WHY A FRAGMENT AND NOT THE FIELDS RENDERED INTO THE PAGE
 * ===========================================================================
 * The obvious alternative is to print one hidden form per card and let script
 * reveal the right one. For a shopper with eight addresses that is eight
 * copies of a six-field form — with eight governorate selects, each carrying
 * every Egyptian governorate as an <option> — in the DOM of a page that shows
 * none of them. Fetching one form when the dialog opens is a request the
 * shopper asked for, against a payload the page would otherwise carry always
 * (CLAUDE.md section 13: less DOM, fewer bytes).
 *
 * The other alternative — populating a single blank form from data attributes
 * on each card — needs every field's value in the card markup, which is the
 * same weight plus a second copy of the mapping.
 *
 * ===========================================================================
 * OWNERSHIP IS CORE'S CHECK, DELIBERATELY
 * ===========================================================================
 * The `id` arrives from the browser, and this action does NOT check it. That
 * is not an omission: Magento\Customer\Block\Address\Edit::initAddressObject()
 * already compares the address's customer against the session's and quietly
 * substitutes a blank new-address form when they differ. So a probe for
 * somebody else's address id gets the same empty form as `?id=` with no value
 * — no data, and no signal that the id exists.
 *
 * Repeating the check here would add a second answer to the same question,
 * with the chance of the two disagreeing. What this action DOES enforce is
 * that there is a session at all: `AccountInterface` is the marker Magento's
 * own AccountAuthorization plugin uses to redirect an anonymous request to the
 * login page before execute() runs.
 *
 * ===========================================================================
 * GET, AND WHY THAT IS SAFE
 * ===========================================================================
 * It reads. Nothing here writes, so there is no state for a forged cross-site
 * request to change, which is exactly the condition under which Magento
 * expects an action to be a GET and not carry a form key. The form this
 * returns is a different matter: it posts to core's own
 * `customer/address/formPost`, which is CSRF-checked, and the key inside it is
 * rendered by the template from the session's own form key.
 */
class Form implements HttpGetActionInterface, AccountInterface
{
    public function __construct(
        private readonly LayoutFactory $layoutFactory
    ) {
    }

    /**
     * A Layout result, not a Page: it renders the handle's own output with none
     * of the page's chrome — no <html>, no header, no footer — which is what
     * makes the response injectable into the dialog as-is.
     */
    public function execute(): Layout
    {
        return $this->layoutFactory->create();
    }
}
