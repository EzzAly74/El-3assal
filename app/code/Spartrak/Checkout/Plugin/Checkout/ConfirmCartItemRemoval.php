<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Plugin\Checkout;

use Magento\Checkout\Helper\Cart;

/**
 * Makes the cart line's remove button ask before it removes.
 *
 * ===========================================================================
 * WHY A PLUGIN ON THE HELPER AND NOT A TEMPLATE OVERRIDE
 * ===========================================================================
 * The bin is rendered by `Magento_Checkout::cart/item/renderer/actions/
 * remove.phtml`, whose whole body is one anchor carrying
 * `data-post='<?= $block->getDeletePostJson() ?>'`. That JSON comes from THIS
 * helper method, and every product type's renderer - default, simple, virtual,
 * configurable, grouped, bundle, downloadable - goes through it.
 *
 * So there is exactly one place to add the question, and it is here. Overriding
 * the template instead would mean re-encoding JSON inside a .phtml (business
 * logic in a template, CLAUDE.md §8) and would still only cover the renderers
 * that happen to use that one file.
 *
 * ===========================================================================
 * THE DIALOG IS MAGENTO'S OWN, NOT A NEW ONE
 * ===========================================================================
 * `mage/dataPost` - the widget that already handles every `data-post` element
 * on the storefront - reads exactly two keys and opens
 * `Magento_Ui/js/modal/confirm` when it finds them:
 *
 *     if (params.data.confirmation) {
 *         uiConfirm({ content: params.data.confirmationMessage, ... });
 *     }
 *
 * So adding the keys is the entire implementation. No JavaScript is written, no
 * modal is built, no click handler is intercepted, and the confirmed POST is
 * still core's own - same URL, same form key, same controller.
 *
 * ===========================================================================
 * WHY THE MESSAGE IS NOT MORE SPECIFIC
 * ===========================================================================
 * It would read better naming the product. The helper is handed the item and
 * could. It does not, because `confirmationMessage` is rendered into the page
 * as a JSON attribute on every cart line, and a product name is merchant data
 * that can contain quotes, angle brackets and RTL marks - so naming it would
 * put untrusted text into an attribute for the sake of a nicer sentence. The
 * shopper is looking at the line they clicked; the question is unambiguous
 * without it.
 */
class ConfirmCartItemRemoval
{
    /**
     * @param Cart $subject
     * @param string $result core's `{"action": "...", "data": {...}}`
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetDeletePostJson(Cart $subject, $result)
    {
        $payload = json_decode((string) $result, true);

        /**
         * Anything other than the shape this plugin was written against is
         * handed back untouched. A malformed or restructured payload is core's
         * or another module's business, and a remove button that still works
         * without a confirmation is a far better failure than one that throws.
         */
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return $result;
        }

        $payload['data']['confirmation'] = true;
        $payload['data']['confirmationMessage'] = (string) __('Remove this product from your cart?');

        return json_encode($payload);
    }
}
