<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\Controller\Adminhtml\Proof;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;
use Spartrak\InstaPay\Model\ProofStorage;

/**
 * Streams one transfer receipt to an authorised admin.
 *
 * ===========================================================================
 * THIS CONTROLLER IS THE ONLY WAY TO SEE THESE FILES
 * ===========================================================================
 * The receipts live in var/spartrak/instapay, outside the document root, so
 * there is no URL that reaches them directly - by design. Every view therefore
 * passes through here, which means every view is behind the admin session and
 * behind an ACL resource a merchant can withhold from roles that should not see
 * customers' banking screenshots. See Model\ProofStorage for the full argument.
 *
 * ===========================================================================
 * WHY THE RESPONSE IS LOCKED DOWN THE WAY IT IS
 * ===========================================================================
 * The bytes came from an anonymous upload, so the response has to assume the
 * file is hostile even though it was type-checked on the way in:
 *
 *   X-Content-Type-Options: nosniff  stops a browser from deciding a file
 *                                    declared as an image is really HTML and
 *                                    running it in the admin's session.
 *   Content-Security-Policy           a sandbox with no allowed sources, so
 *                                    even if something did get parsed as a
 *                                    document it can load and run nothing.
 *   Content-Disposition: inline       so it renders in the order view where it
 *                                    is useful, with a filename that is
 *                                    generated rather than customer-supplied.
 */
class View extends Action implements HttpGetActionInterface
{
    /**
     * Magento checks this before execute() runs. It is the module's own
     * resource, not Magento_Sales::actions_view, so a role can be given order
     * access without being given receipt access.
     */
    public const ADMIN_RESOURCE = 'Spartrak_InstaPay::proof';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly TransferRepositoryInterface $transferRepository,
        private readonly ProofStorage $proofStorage,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->rawFactory->create();
        $transferId = (int) $this->getRequest()->getParam('transfer_id');

        try {
            $transfer = $this->transferRepository->getById($transferId);
            $path = $this->proofStorage->getAbsolutePath($transfer->getProofPath());
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException('The receipt file could not be read.');
            }
        } catch (\Exception $e) {
            $this->logger->warning('Spartrak InstaPay: a receipt could not be served.', [
                'transfer_id' => $transferId,
                'exception'   => $e,
            ]);

            return $result->setHttpResponseCode(404)->setContents('');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = $extension === 'heic' ? 'image/heic' : 'image/jpeg';

        $result->setHeader('Content-Type', $type, true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setHeader('Content-Security-Policy', "default-src 'none'; sandbox", true);
        $result->setHeader(
            'Content-Disposition',
            sprintf('inline; filename="instapay-receipt-%d.%s"', $transferId, $extension),
            true
        );
        // A receipt is per-order evidence, never a shared asset.
        $result->setHeader('Cache-Control', 'private, no-store', true);

        return $result->setContents($contents);
    }
}
