<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Controller\Adminhtml\Consignment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterfaceFactory;
use Spartrak\PickupLocation\Model\PickupType;
use Spartrak\PickupLocation\Model\VehiclePhotoStorage;
use Spartrak\PickupLocation\ViewModel\OrderPickup;

/**
 * Saves the driver and vehicle details for a `موقف` order.
 *
 * BUSINESS.md section 12, §4 lists the six required facts. Five are posted
 * here; the sixth, `الي الموقف`, is the depot the customer chose at checkout
 * and is already on the order — it is displayed, not collected.
 *
 * ===========================================================================
 * WHY THIS IS A SEPARATE FORM AND NOT PART OF THE STATUS DROPDOWN
 * ===========================================================================
 * §4 asks for the form to be "impossible to skip". The instinct is to bolt the
 * fields onto the order view's Comments History block, so the driver details
 * and the status change are one submit — but that block posts by AJAX to
 * sales/order/addComment, and a file upload cannot ride an AJAX form without
 * either a second request or FormData plumbing of our own.
 *
 * The photograph is not negotiable (§4: the customer has to identify the
 * vehicle "in a yard full of near-identical microbuses"), so the form is a
 * real multipart POST of its own, and "impossible to skip" is enforced by
 * Plugin\Sales\RequireConsignmentBeforeDispatch instead: the status change is
 * REJECTED until this has been saved. Same guarantee, and it holds for the REST
 * API too, which no amount of admin-form work would have covered.
 *
 * ===========================================================================
 * VALIDATION
 * ===========================================================================
 * Every text field is required and trimmed. The plate number is free text —
 * §7 question 4 leaves a structured Egyptian plate input undecided, and free
 * text cannot reject a real plate it has not been taught about. The phone is
 * NOT normalised through Spartrak_CustomerAuth's Normalizer: that class exists
 * to canonicalise a number for SMS delivery and rejects anything non-Egyptian
 * -mobile, and a driver's number here is dialled by a human, may be a landline,
 * and is typed by an admin who can see it on screen.
 *
 * CSRF is the platform's: an admin POST action that does not implement
 * CsrfAwareActionInterface gets form-key validation before execute() runs.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Spartrak_PickupLocation::consignment';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ConsignmentRepositoryInterface $consignments,
        private readonly ConsignmentInterfaceFactory $consignmentFactory,
        private readonly VehiclePhotoStorage $photoStorage,
        private readonly OrderPickup $pickup,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create();

        if ($orderId === 0) {
            $this->messageManager->addErrorMessage(__('We could not tell which order that was for.'));

            return $redirect->setPath('sales/order/index');
        }

        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);

        try {
            $order = $this->orderRepository->get($orderId);

            if ($this->pickup->getType($order) !== PickupType::DEPOT) {
                throw new LocalizedException(
                    __('Driver and vehicle details apply only to orders collected from a station.')
                );
            }

            $consignment = $this->consignments->getByOrderId($orderId) ?? $this->consignmentFactory->create();
            $previousPhoto = $consignment->getVehiclePhoto();

            $consignment->setOrderId($orderId);
            $consignment->setDriverName($this->requireField('driver_name', __('the driver\'s name')));
            $consignment->setDriverPhone($this->requireField('driver_phone', __('the driver\'s phone number')));
            $consignment->setPlateNumber($this->requireField('plate_number', __('the vehicle plate number')));
            $consignment->setOriginStation($this->requireField('origin_station', __('the origin station')));

            $photo = $this->getUploadedPhoto();

            if ($photo !== null) {
                $consignment->setVehiclePhoto($this->photoStorage->store($photo));
            } elseif ($previousPhoto === '') {
                // First save with no file attached. Refused rather than stored
                // half-complete, because a consignment without a photograph
                // would not satisfy the dispatch gate anyway and the admin
                // would meet the refusal later, further from the cause.
                throw new LocalizedException(__('Please attach a photo of the vehicle.'));
            }

            $this->consignments->save($consignment);

            if ($photo !== null && $previousPhoto !== '' && $previousPhoto !== $consignment->getVehiclePhoto()) {
                // Only after the row points at the new file, so a failed save
                // never leaves the order with a path to a deleted image.
                $this->photoStorage->deleteQuietly($previousPhoto);
            }

            $this->messageManager->addSuccessMessage(__('Driver and vehicle details saved.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            // Logged, not swallowed — CLAUDE.md section 9. Injected rather
            // than pulled off $this->_objectManager, which a Backend Action
            // exposes and which would be a service locator in a constructor's
            // clothing.
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(__('We could not save the driver and vehicle details.'));
        }

        return $redirect;
    }

    /**
     * @throws LocalizedException
     */
    private function requireField(string $key, \Magento\Framework\Phrase $label): string
    {
        $value = trim((string) $this->getRequest()->getParam($key, ''));

        if ($value === '') {
            throw new LocalizedException(__('Please enter %1.', $label));
        }

        return $value;
    }

    /**
     * The uploaded photograph, or null when the admin is editing the text
     * fields of a consignment that already has one.
     *
     * @return array{name?: string, type?: string, tmp_name?: string, size?: int, error?: int}|null
     */
    private function getUploadedPhoto(): ?array
    {
        $files = $this->getRequest()->getFiles('vehicle_photo');

        if (!is_array($files) || ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $files;
    }
}
