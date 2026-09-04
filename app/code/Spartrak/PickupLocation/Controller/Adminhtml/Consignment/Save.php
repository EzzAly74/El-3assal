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
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterfaceFactory;
use Spartrak\PickupLocation\Model\ConsignmentAudit;
use Spartrak\PickupLocation\Model\LocationCatalog;
use Spartrak\PickupLocation\Model\OrderPickupSnapshot;
use Spartrak\PickupLocation\Model\PickupType;
use Spartrak\PickupLocation\Model\VehiclePhotoStorage;
use Spartrak\PickupLocation\ViewModel\OrderPickup;

/**
 * Saves the driver, the vehicle and — when it has to be established by hand —
 * the destination station for a `موقف` order.
 *
 * BUSINESS.md section 12, §4 lists the six required facts. Five are posted
 * here. The sixth, `الي الموقف`, is normally the depot the customer chose at
 * checkout and is displayed rather than collected — but section 9 question 5
 * decided that the admin may record it when the checkout snapshot never landed,
 * and may redirect it when the chosen station is unreachable on the route. That
 * field is therefore posted too, from a list rather than as free text, and it
 * writes an OVERRIDE without ever touching the order's own snapshot.
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
 * Observer\RequireConsignmentBeforeDispatch instead: the status change is
 * REJECTED until this has been saved. Same guarantee, and it holds for the REST
 * API too, which no amount of admin-form work would have covered.
 *
 * ===========================================================================
 * VALIDATION
 * ===========================================================================
 * Every text field is required and trimmed. The plate number is free text —
 * §9 question 4 settles on free text, because a structured Egyptian plate input
 * would reject the legitimate variants a dispatcher copies off a real plate.
 * The phone is NOT normalised through Spartrak_CustomerAuth's Normalizer: that
 * class exists to canonicalise a number for SMS delivery and rejects anything
 * that is not an Egyptian mobile, and a driver's number here is dialled by a
 * human, may be a landline, and is typed by an admin who can see it on screen.
 *
 * A DESTINATION THAT REPLACES THE CUSTOMER'S OWN CHOICE REQUIRES A REASON.
 * Filling a blank does not. The difference is not bureaucracy: one is repairing
 * our own missing data, the other is sending somebody's goods somewhere they
 * did not ask for, and §9 question 5 makes the reason the condition on which
 * that is allowed at all.
 *
 * CSRF is the platform's: an admin POST action that does not implement
 * CsrfAwareActionInterface gets form-key validation before execute() runs.
 *
 * ===========================================================================
 * EVERY SAVE IS WRITTEN TO THE ORDER'S HISTORY
 * ===========================================================================
 * §6 requires it for a driver or vehicle swapped after dispatch, and the
 * reason is worth restating: notification is on-site only (§7), so the
 * customer's card changes under them silently. When they ring to ask why the
 * number they wrote down no longer works, the history is the only place the
 * answer exists. Model\ConsignmentAudit turns the change into that sentence.
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
        private readonly OrderPickupSnapshot $snapshot,
        private readonly LocationCatalog $locations,
        private readonly ConsignmentAudit $audit,
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
            // Taken BEFORE the form is applied — the model below is mutated in
            // place, so a reference to it would diff against itself.
            $before = $this->audit->capture($consignment->getConsignmentId() === null ? null : $consignment);

            $consignment->setOrderId($orderId);
            $consignment->setDriverName($this->requireField('driver_name', __('the driver\'s name')));
            $consignment->setDriverPhone($this->requireField('driver_phone', __('the driver\'s phone number')));
            $consignment->setPlateNumber($this->requireField('plate_number', __('the vehicle plate number')));
            $consignment->setOriginStation($this->requireField('origin_station', __('the origin station')));

            $this->applyDestination($consignment, $order);

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

            // AFTER the consignment is saved, not before: the order save below
            // passes through the dispatch gate, and on an order that is already
            // out for delivery the gate has to see the completed row.
            $this->recordHistory($order, $before, $consignment);

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
     * `الي الموقف` — the destination override, or nothing.
     *
     * ===================================================================
     * FROM THE DEPOT LIST, NOT AS FREE TEXT
     * ===================================================================
     * A destination can only ever be one of the stations this store offers:
     * the customer's checkout list IS `spartrak_pickup_depot` (§9 question 2).
     * Selecting from it means the customer's card carries a real name, street,
     * governorate and operator rather than one typed string, and it keeps a
     * station that is missing from the network fixable in the one place that
     * also fixes checkout. A station nobody can select is a station to ADD
     * under Pickup Locations, not to type onto one order.
     *
     * This is the opposite decision from `origin_station` a few lines above,
     * and deliberately so: §4 says the origin "depends on which vehicle is
     * going, and from where", i.e. it is a fact about the journey rather than a
     * place in our network.
     *
     * ===================================================================
     * CHOOSING THE CUSTOMER'S OWN STATION CLEARS THE OVERRIDE
     * ===================================================================
     * Rather than storing a copy of it. An override that agrees with the
     * snapshot is a second source of truth that adds nothing and can drift
     * from the first — and `isRedirected()` would then report a redirect that
     * never happened.
     *
     * @throws LocalizedException
     */
    private function applyDestination(ConsignmentInterface $consignment, OrderInterface $order): void
    {
        $depotId = (int) $this->getRequest()->getParam('destination_id', 0);
        $reason = trim((string) $this->getRequest()->getParam('destination_reason', ''));
        $customerChoiceId = $this->snapshot->getLocationId($order);
        $customerChoiceName = $this->snapshot->getName($order);

        if ($depotId <= 0) {
            /**
             * Nothing selected, i.e. "use the customer's own choice". On an
             * order that HAS a snapshot this is the normal state. On an order
             * that does not, it leaves the destination unknown — which the
             * panel reports prominently rather than pretending is fine, and
             * which is the dispatcher's own call to make: §6 does not block a
             * dispatch on it, because the parcel still has to travel.
             *
             * EXCEPT when the station already recorded here is not in the
             * current list — its depot has been disabled or deleted since. The
             * select cannot pre-select it, so an empty submission would mean
             * "the admin cleared it" when in fact the control could not offer
             * it. Kept, and the panel says the record needs re-confirming.
             */
            if ($consignment->hasDestination() && !$this->isSelectable($consignment->getDestinationId())) {
                return;
            }

            $consignment->setDestination(null, null, null, null);

            return;
        }

        $depot = $this->locations->getDepotById($depotId);

        if ($depot === null) {
            throw new LocalizedException(
                __('That station is no longer available. Choose another, or add it under Pickup Locations.')
            );
        }

        $name = trim((string) ($depot['name'] ?? ''));

        if ($this->isCustomersOwnChoice($depotId, $name, $customerChoiceId, $customerChoiceName)) {
            $consignment->setDestination(null, null, null, null);

            return;
        }

        if ($customerChoiceName !== null && $reason === '') {
            throw new LocalizedException(
                __(
                    'This order is going to %1 because the customer chose it. '
                    . 'To send it to %2 instead, say why — it is written to the order history, '
                    . 'and it is the only record the customer will have of the change.',
                    $customerChoiceName,
                    $name
                )
            );
        }

        $consignment->setDestination(
            $depotId,
            $name,
            (string) ($depot['address'] ?? ''),
            $reason !== '' ? $reason : null
        );
    }

    /**
     * Can this depot id still be chosen from the panel's list?
     *
     * The list is the ACTIVE network, so this is false for a station that has
     * been disabled as well as one that has been deleted.
     */
    private function isSelectable(?int $depotId): bool
    {
        return $depotId !== null && $this->locations->getDepotById($depotId) !== null;
    }

    /**
     * Is the selected depot the same place the customer picked?
     *
     * By ID where the order carries one, because that is exact. By NAME
     * otherwise — an older order may have a name with no id, and two depots do
     * not share a name in a list an admin curates.
     */
    private function isCustomersOwnChoice(
        int $depotId,
        string $name,
        ?int $customerChoiceId,
        ?string $customerChoiceName
    ): bool {
        if ($customerChoiceId !== null) {
            return $customerChoiceId === $depotId;
        }

        return $customerChoiceName !== null && trim($customerChoiceName) === $name;
    }

    /**
     * Writes what changed onto the order's own comment history.
     *
     * `addCommentToStatusHistory($comment, false, false)` — `false` for the
     * status keeps the order exactly where it is (this form must never move an
     * order), and `false` for visibility keeps the entry off the customer's
     * order page. It is an operational record for the people answering the
     * phone; §7's decision that there is no notification is not this method's
     * to reverse by publishing changes to the storefront.
     *
     * Magento's OrderManagementInterface::addComment() was the obvious
     * alternative and is not used: it hands the history to the ORDER NOTIFIER,
     * which is a mail path, and these entries must not become email.
     *
     * A failure here is logged and swallowed on purpose. The consignment IS
     * saved by this point and the driver's details are live on the customer's
     * card; refusing the whole save over a missing history line would be
     * losing the fact to protect the record of it.
     */
    private function recordHistory(
        OrderInterface $order,
        array $before,
        ConsignmentInterface $consignment
    ): void {
        if (!$order instanceof Order) {
            return;
        }

        $entry = $this->audit->describe($before, $consignment, $this->snapshot->getName($order));

        if (!$entry instanceof Phrase) {
            // Nothing changed — an admin who opened the panel and pressed save
            // without touching anything does not add a row.
            return;
        }

        try {
            $order->addCommentToStatusHistory((string) $entry, false, false);
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->error('Spartrak Pickup: the consignment change could not be written to the order history.', [
                'order_id' => (int) $order->getEntityId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @throws LocalizedException
     */
    private function requireField(string $key, Phrase $label): string
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
