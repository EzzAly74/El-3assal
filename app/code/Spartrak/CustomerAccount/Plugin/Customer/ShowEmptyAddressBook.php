<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Plugin\Customer;

use Magento\Customer\Controller\Address\Index;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Keeps "عناويني" on "عناويني" when the shopper has no addresses yet.
 *
 * ===========================================================================
 * WHAT IT FIXES
 * ===========================================================================
 * A customer with an empty address book who opened /customer/address/ was
 * bounced to /customer/address/new and shown Magento's stock two-column form —
 * "CONTACT INFORMATION" / "ADDRESS", in English, with Street Address Line 1,
 * Country, State/Province, City and Zip/Postal Code. Every one of those is a
 * field this storefront's design deliberately does not ask for
 * (Spartrak_CustomerAddress derives the city, Egypt's postcode is optional,
 * there is one country), and the page is drawn by no Figma frame at all.
 *
 * The redirect is core's:
 *
 *     Magento\Customer\Controller\Address\Index::execute()
 *         $addresses = ...->getAddresses();
 *         if (count($addresses)) { return $resultPage; }
 *         return ...->setPath('*\/*\/new');
 *
 * Its reasoning is sound for Luma, where the address book page is a bare list
 * and an empty list is a blank screen. It is wrong here: Figma's address book
 * (562:18126) is a CARD — a heading, an explanatory line and an
 * "اضف عنوان جديد" action that opens the form in a dialog — and
 * address/spartrak-book.phtml already renders an empty state inside it. There
 * is always a page worth showing, and the shopper is still one click from the
 * form, in the place the rail says they are.
 *
 * ===========================================================================
 * WHY `after` AND WHY A REDIRECT IS SAFE TO RECOGNISE
 * ===========================================================================
 * `execute()` returns exactly two things: the page, or that one redirect. So
 * "the result is a Redirect" is a complete and stable test for "core decided
 * there was nothing to list" — no need to re-read the address count, and no
 * `around` closure wrapped around every visit to the page.
 *
 * Authentication is not a case this has to think about: an anonymous request
 * never reaches execute(). Magento\Customer\Controller\Plugin\Account plugs
 * `dispatch()` and redirects to the login page from there, before any action
 * method runs.
 *
 * Building the page here rather than in an `around` also means core keeps
 * doing the work it is good at on the normal path — this class touches nothing
 * when the shopper has addresses.
 *
 * ===========================================================================
 * WHAT IT DOES NOT DO
 * ===========================================================================
 * It does not disable, hide or replace /customer/address/new. That page is
 * still the no-JavaScript route to the form, still where core's own FormPost
 * sends a shopper whose save failed validation, and still a valid bookmark —
 * which is why the theme now dresses it too, in
 * Magento_Customer/layout/customer_address_form.xml. This only stops it being
 * somewhere a shopper is sent when they asked for their address book.
 */
class ShowEmptyAddressBook
{
    public function __construct(
        private readonly PageFactory $resultPageFactory
    ) {
    }

    /**
     * @param Index $subject
     * @param ResultInterface $result
     */
    public function afterExecute(Index $subject, ResultInterface $result): ResultInterface
    {
        if (!$result instanceof Redirect) {
            return $result;
        }

        /*
         * The `customer_address_index` handle, i.e. the same page core would
         * have returned — the theme's own layout for it removes core's
         * "Default Addresses" panel and re-templates the grid, so the empty
         * card comes out of address/spartrak-book.phtml with no further help.
         *
         * Core sets a referer URL on its `address_book` block on this path;
         * that block is removed by this theme (see the layout file), so there
         * is nothing here to carry over.
         */
        return $this->resultPageFactory->create();
    }
}
