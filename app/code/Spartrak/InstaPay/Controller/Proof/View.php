<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Controller\Proof;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;
use Spartrak\InstaPay\Model\ProofStorage;

/**
 * Streams one transfer receipt back to the customer who uploaded it.
 *
 * ===========================================================================
 * THIS IS THE SECOND DOOR TO A PRIVATE FILE. IT IS BUILT LIKE THE FIRST.
 * ===========================================================================
 * Receipts live in var/spartrak/instapay, outside the document root, so no URL
 * reaches them directly — by design (Model\ProofStorage). Until now the only
 * door was Controller\Adminhtml\Proof\View, behind an admin session and an ACL.
 * This one is for the person whose banking screenshot it is, because Figma puts
 * the receipt on their own order page (573:21514) and the question it answers —
 * "did the right screenshot arrive?" — is theirs to ask.
 *
 * A private file with a second door is exactly the shape that leaks, so the
 * guard is written out in full and fails closed at every step:
 *
 *   1. there must be a signed-in customer;
 *   2. the transfer must exist;
 *   3. the ORDER it belongs to must exist;
 *   4. that order's customer_id must equal the session's customer id.
 *
 * Step 4 is the one that matters. `transfer_id` is a sequential integer in the
 * URL, so without it a signed-in shopper could walk the range and read every
 * receipt on the store. It is checked against the ORDER rather than against
 * anything on the transfer row itself, because the order is where ownership is
 * recorded and where Magento's own order pages check it.
 *
 * ===========================================================================
 * EVERY FAILURE IS THE SAME 404
 * ===========================================================================
 * Not a redirect, not a message, not a 403. A distinguishable response would
 * turn this endpoint into an oracle: "403" says the id exists and belongs to
 * somebody else, "404" says it does not exist, and the difference is enough to
 * enumerate the store's transfer volume. One answer for "not yours or not
 * there" gives an attacker nothing, and a customer following a link from their
 * own order page never sees it.
 *
 * The reason is logged at warning with the ids, so a real fault is still
 * diagnosable — CLAUDE.md section 9 rules out swallowing errors silently, and
 * an opaque response to the CLIENT is not the same thing as an opaque one to
 * the operator.
 *
 * ===========================================================================
 * THE RESPONSE HEADERS ARE THE ADMIN CONTROLLER'S, AND FOR THE SAME REASONS
 * ===========================================================================
 * The bytes came from an anonymous upload and are treated as hostile even
 * though the type was verified from the file's own magic on the way in:
 *
 *   X-Content-Type-Options: nosniff  a browser must not decide that a file
 *                                    declared as an image is really HTML and
 *                                    run it in the customer's session.
 *   Content-Security-Policy          a sandbox with no allowed sources, so even
 *                                    a parsed document can load and run nothing.
 *   Cache-Control: private, no-store  per-order evidence, never a shared asset,
 *                                    and never left in a proxy or a shared
 *                                    browser cache.
 *   Content-Disposition: inline       it renders in the row where it is useful,
 *                                    under a generated filename rather than the
 *                                    customer-supplied one.
 */
class View implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly CustomerSession $customerSession,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProofStorage $proofStorage,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawFactory->create();
        $transferId = (int) $this->request->getParam('transfer_id');

        try {
            if (!$this->customerSession->isLoggedIn()) {
                throw new \RuntimeException('No signed-in customer.');
            }

            $transfer = $this->transferRepository->getById($transferId);
            $order = $this->orderRepository->get($transfer->getOrderId());

            $ownerId = (int) $order->getCustomerId();
            $viewerId = (int) $this->customerSession->getCustomerId();

            if ($ownerId === 0 || $ownerId !== $viewerId) {
                throw new \RuntimeException(
                    sprintf('Receipt belongs to customer %d, requested by %d.', $ownerId, $viewerId)
                );
            }

            $path = $this->proofStorage->getAbsolutePath($transfer->getProofPath());
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException('The receipt file could not be read.');
            }
        } catch (\Exception $e) {
            $this->logger->warning('Spartrak InstaPay: a customer receipt request was refused.', [
                'transfer_id' => $transferId,
                'customer_id' => (int) $this->customerSession->getCustomerId(),
                'exception'   => $e,
            ]);

            return $result->setHttpResponseCode(404)->setContents('');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        // ProofStorage only ever stores these two, and the extension it stores
        // is derived from the file's own bytes rather than its name.
        $type = $extension === 'heic' ? 'image/heic' : 'image/jpeg';

        $result->setHeader('Content-Type', $type, true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setHeader('Content-Security-Policy', "default-src 'none'; sandbox", true);
        $result->setHeader(
            'Content-Disposition',
            sprintf('inline; filename="instapay-receipt-%d.%s"', $transferId, $extension),
            true
        );
        $result->setHeader('Cache-Control', 'private, no-store', true);

        return $result->setContents($contents);
    }
}
