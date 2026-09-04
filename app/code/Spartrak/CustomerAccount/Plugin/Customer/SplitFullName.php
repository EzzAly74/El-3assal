<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\Plugin\Customer;

use Magento\Customer\Controller\Account\EditPost;
use Magento\Framework\App\RequestInterface;

/**
 * Turns the account card's one name field back into Magento's firstname /
 * lastname pair — Figma 821:17286.
 *
 * ===========================================================================
 * WHY THE FORM ASKS ONCE
 * ===========================================================================
 * Figma's edit card draws a single "اسم المستخدم" input holding a whole name,
 * and its read state prints a single joined value. Magento stores two
 * attributes, both required.
 *
 * Those reconcile in exactly one of two places: the form, by asking twice, or
 * the request, by splitting once. The form was doing the first — two inputs
 * behind one border — and it showed: a name broken across two visibly unequal
 * boxes, which is not what the frame draws. So the split happens here.
 *
 * ===========================================================================
 * WHERE IT SPLITS, AND WHAT HAPPENS TO ONE-WORD NAMES
 * ===========================================================================
 * On the FIRST run of whitespace, into at most two parts: everything after the
 * first token is the last name. "محمد أشرف الفاضلي" gives "محمد" and
 * "أشرف الفاضلي", which is the reading an Egyptian shopper expects — a
 * last-token split would make "الفاضلي" the surname and lose the middle name
 * into the first.
 *
 * A single word goes into BOTH fields. Not into firstname alone: `lastname` is
 * required by core's own validation, so an empty one fails the save with an
 * error about a field this form does not show — a dead end. Not a placeholder
 * character either, which would print as part of the shopper's name everywhere
 * the account is displayed. Repeating the word is the only option that saves,
 * and it round-trips: Profile::getName() joins the pair and de-duplicates, so
 * the card shows the one word back.
 *
 * ===========================================================================
 * WHY A PLUGIN AND NOT AN OBSERVER
 * ===========================================================================
 * The value has to be in the request BEFORE EditPost::execute() reads it —
 * `_extractCustomer()` populates the customer from the posted data on the
 * first line of the save. `beforeExecute` is the last point where the request
 * is still editable; an observer on a customer save event fires long after the
 * validation that would already have rejected the missing pair.
 *
 * ===========================================================================
 * IT IS INERT ON ANY OTHER FORM
 * ===========================================================================
 * Keyed on `spartrak_fullname`, which only this card posts. A request that
 * sends firstname and lastname directly — the admin, the REST API, a
 * third-party form — passes through untouched, so nothing else on the platform
 * has to know this field exists.
 */
class SplitFullName
{
    private const FIELD = 'spartrak_fullname';

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @param EditPost $subject
     * @return void
     */
    public function beforeExecute(EditPost $subject): void
    {
        $full = trim((string) $this->request->getParam(self::FIELD));

        if ($full === '') {
            return;
        }

        // Any run of whitespace, including the non-breaking space a paste from
        // Word arrives with, and the Arabic-script spacing an IME can emit.
        $parts = preg_split('/\s+/u', $full, 2, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return;
        }

        $this->request->setPostValue('firstname', $parts[0]);
        $this->request->setPostValue('lastname', $parts[1] ?? $parts[0]);
    }
}
