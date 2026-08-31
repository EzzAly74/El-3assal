<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Block;

use Magento\Payment\Block\Info as PaymentInfo;

/**
 * The payment block on an order view, an invoice and the order email.
 *
 * ===========================================================================
 * WHAT IT DELIBERATELY DOES NOT SHOW
 * ===========================================================================
 * Not the transfer receipt, and not the customer's phone number.
 *
 * This block is rendered into the ORDER CONFIRMATION EMAIL as well as the admin
 * screen, and an email is plain text over the wire that ends up in an inbox,
 * a mail archive and any forwarding rule the customer has. A banking screenshot
 * does not belong in one.
 *
 * The receipt lives behind an ACL and an admin session instead - see
 * Block\Adminhtml\Order\View\Transfer and Controller\Adminhtml\Proof\View. This
 * block says only which method was used, which is what a payment block is for.
 */
class Info extends PaymentInfo
{
    protected $_template = 'Spartrak_InstaPay::info/default.phtml';
}
