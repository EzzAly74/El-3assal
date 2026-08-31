<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Controller\Note;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores the shopper's order note on the quote.
 *
 * ===========================================================================
 * WHY customer_note AND NOT A NEW COLUMN
 * ===========================================================================
 * `quote.customer_note` already exists in Magento_Quote's schema, is part of
 * CartInterface, and Magento_Sales' own fieldset.xml already copies it to
 * `sales_order.customer_note` when the quote is converted. The admin order view
 * already displays it.
 *
 * So the entire "note" feature is one write to a column Magento maintains -
 * no table, no extension attribute, no observer, and it shows up in the admin
 * and in the order confirmation email without another line of code. Adding a
 * `spartrak_order_note` column instead would have been a parallel field that
 * every one of those places would then have had to be taught about.
 *
 * ===========================================================================
 * WHY A CONTROLLER AND NOT THE CHECKOUT REST PAYLOAD
 * ===========================================================================
 * Figma gives the note an explicit `أضف التعليق` button (720:26810) rather
 * than folding it into the order submission, so the shopper gets confirmation
 * that it was recorded before they commit. A discrete POST matches that; piggy-
 * backing on the payment payload would only tell them at the very end, when it
 * is too late to correct.
 */
class Save implements HttpPostActionInterface
{
    /**
     * `customer_note` is a TEXT column, so the database would take far more.
     * This is a shopper's note to a warehouse, not a document; anything past a
     * couple of paragraphs is either a mistake or an attempt to use the field
     * as storage. Truncating rather than rejecting keeps a long-but-genuine
     * note from being lost entirely.
     */
    private const MAX_LENGTH = 1000;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly JsonFactory $resultJsonFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        /**
         * Stored raw and escaped at every render point - the admin order view
         * and the order email both escape it, as does the order success page.
         * Sanitising on the way IN would silently corrupt a legitimate note
         * containing an ampersand or an angle bracket, and would still not be a
         * substitute for escaping on the way out.
         */
        $note = (string) $this->request->getParam('note', '');
        $note = trim($note);

        if (mb_strlen($note) > self::MAX_LENGTH) {
            $note = mb_substr($note, 0, self::MAX_LENGTH);
        }

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId()) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Your cart is empty.'),
                ]);
            }

            $quote->setCustomerNote($note);
            // Only the note changed; a full quote save would re-run the totals
            // collector and could reset a shipping selection mid-checkout.
            $quote->getResource()->saveAttribute($quote, 'customer_note');
        } catch (\Exception $e) {
            // Logged rather than swallowed: a note that silently fails to save
            // is a delivery instruction the warehouse never sees.
            $this->logger->error('Spartrak: could not save the order note.', ['exception' => $e]);

            return $result->setData([
                'success' => false,
                'message' => __('We could not save your note. Please try again.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'message' => __('Your note has been saved.'),
        ]);
    }
}
