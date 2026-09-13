<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;
use Spartrak\PickupLocation\Model\ConsignmentRequirements;
use Spartrak\PickupLocation\Model\DeliveryStatus;
use Spartrak\PickupLocation\Model\PickupType;
use Spartrak\PickupLocation\ViewModel\OrderPickup;

/**
 * THE GATE.
 *
 * BUSINESS.md section 12 (`.claude/elassal-pickup-mawqef-feature.md`), §4,
 * stated as a hard rule rather than a suggestion:
 *
 *   > When the admin changes an order's status to out for delivery
 *   > (`شحنتك في الطريق اليك`), they MUST supply the vehicle and driver
 *   > details. The status change is not complete without them.
 *
 * ===========================================================================
 * WHY AN OBSERVER, AND NOT A PLUGIN ON OrderRepositoryInterface
 * ===========================================================================
 * This WAS a `beforeSave` plugin on OrderRepositoryInterface, which looks like
 * the obvious service-contract chokepoint and is not one.
 *
 * VERIFIED in core: Magento\Sales\Controller\Adminhtml\Order\AddComment — the
 * screen the admin actually changes a status on — does
 *
 *     $order->setStatus($orderStatus);
 *     $history = $order->addStatusHistoryComment($comment, $orderStatus);
 *     $history->save();
 *     $order->save();
 *
 * `$order->save()` is AbstractModel::save(), which never touches the
 * repository. A repository plugin would therefore have been bypassed by the one
 * screen the rule exists for, while looking perfectly correct in review.
 *
 * `sales_order_save_before` is dispatched by AbstractModel::beforeSave() and so
 * fires on BOTH paths — the direct model save above and
 * OrderRepository::save(), which reaches the same resource model. That makes it
 * the real chokepoint: admin form, REST, a console script or a mass action all
 * pass through it.
 *
 * ===========================================================================
 * IT GATES THE TRANSITION. IT USED TO GATE THE STATE, AND THAT WAS A BUG.
 * ===========================================================================
 * This was written as an invariant on the reading that it is "simpler and
 * stronger":
 *
 *     a `موقف` order may not BE in `شحنتك في الطريق اليك`
 *     unless a complete consignment exists for it
 *
 * It is stronger than the rule BUSINESS.md section 12 §4 actually states,
 * which is about the moment of CHANGE: "when the admin changes an order's
 * status to out for delivery, they MUST supply the vehicle and driver
 * details". Enforcing it as a standing property instead meant refusing EVERY
 * subsequent write to an order already in that state — an invoice, a shipment,
 * a comment, a grid edit, anything at all — for as long as its consignment
 * stayed incomplete.
 *
 * WHAT THAT COST IN PRODUCTION, traced from var/log with a stack:
 *
 *   A shopper changed their email address on the account card. core's
 *   Magento\Customer\Observer\UpgradeOrderCustomerEmailObserver
 *   (customer_save_after_data_object) syncs a changed address onto every order
 *   the customer already has — `$orders->setDataToAll(CUSTOMER_EMAIL, ...)`
 *   then `$orders->save()`. One of that customer's 47 orders was a depot order
 *   sitting at `شحنتك في الطريق اليك` with an incomplete consignment, so this
 *   observer refused the save. CustomerRepository's TransactionWrapper rolled
 *   the whole customer save back, EditPost caught the LocalizedException and
 *   published getMessage() to the storefront — so a shopper editing their own
 *   profile was shown an instruction about a driver's licence plate, and was
 *   signed out (clearFor() stamps the session cutoff BEFORE the save it then
 *   lost).
 *
 * A refusal aimed at an admin changing a status had reached a customer editing
 * a contact detail, through a core observer neither of them can see.
 *
 * So the test is now "is THIS SAVE putting the order into that status", read
 * from `getOrigData('status')` — the value the row held when it was loaded,
 * which AbstractModel has already populated by the time beforeSave() fires. No
 * reload, no extra query.
 *
 * AN UNKNOWN PREVIOUS STATUS STILL ENFORCES. That was the original objection to
 * intercepting the transition — original data is empty for an
 * integration-built order — and it is answered by failing SAFE rather than by
 * widening the rule: the gate stands down only when it can positively see the
 * order was ALREADY out for delivery before this save. A missing, null or
 * different previous status is treated as a transition and refused, so every
 * path the rule was written for behaves exactly as it did.
 *
 * WHAT IS GIVEN UP, STATED PLAINLY: an order that reached this status without a
 * complete consignment — set before this gate existed, or by a path that
 * bypassed it — is no longer frozen. Its data stays wrong until an admin fills
 * the panel in. Freezing it was never protecting the customer anyway; it was
 * only blocking whoever touched the order next, which is how this was found.
 *
 * ===========================================================================
 * SCOPE: DEPOT ORDERS ONLY
 * ===========================================================================
 * `شحنتك في الطريق اليك` is a station on the tracker for EVERY order, home
 * delivery included (§5, "Rest of the page ... unchanged by this feature").
 * The driver card is specific to `الموقف`, so the gate is too:
 *
 *   - depot pickup  -> gated. A named driver in a named vehicle is exactly
 *                      what this fulfilment method is.
 *   - branch pickup -> NOT gated. The customer collects from an ElAssal branch;
 *                      there is no driver and no vehicle to photograph.
 *   - home delivery -> NOT gated.
 *
 * `سوبر جيت` (§7 question 1) is deliberately absent: it is out of scope, has no
 * carrier of its own yet, and its tracking data is expected to be different —
 * so nothing here pretends to cover it.
 *
 * ===========================================================================
 * ONE KNOWN UNTIDINESS, WORTH KNOWING ABOUT
 * ===========================================================================
 * On the admin comment form specifically, `$history->save()` above runs BEFORE
 * `$order->save()`. So a refused dispatch can leave one status-history row
 * naming a status the order never reached. The order itself is correct and the
 * admin sees the refusal inline; the row is cosmetic clutter in the order's
 * comment list.
 *
 * Preventing it would mean also intercepting addStatusHistoryComment(), which
 * covers only that one screen and adds a second place for this rule to live.
 * Left as-is deliberately, and recorded here rather than discovered later.
 */
class RequireConsignmentBeforeDispatch implements ObserverInterface
{
    public function __construct(
        private readonly ConsignmentRepositoryInterface $consignments,
        private readonly OrderPickup $pickup,
        private readonly ConsignmentRequirements $requirements
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order instanceof OrderInterface) {
            return;
        }

        if (!$this->requiresConsignment($order)) {
            return;
        }

        $consignment = $this->findConsignment($order);

        if ($consignment === false || $this->requirements->isSatisfied($consignment)) {
            return;
        }

        /**
         * THE REFUSAL NAMES THE FIELDS.
         *
         * It used to be one fixed sentence, shown identically whether nothing
         * had been entered or four of the five were already filled — so the
         * only way to find out which field was empty was to guess, save, and
         * read the exception again. The list comes from
         * Model\ConsignmentRequirements, which is the same list the panel draws
         * as a checklist, so the two can never name different fields.
         */
        $missing = $this->requirements->getMissingAsText($consignment);

        throw new LocalizedException(
            $missing === ''
                ? __(
                    'Add the driver and vehicle details before marking this order out for delivery. '
                    . 'Open the "Station consignment" section on this order and save it first.'
                )
                : __(
                    'This order cannot be marked out for delivery yet: %1 %2. '
                    . 'Fill them in under "Station consignment" on this order and save, then change the status. '
                    . 'The customer has no other way to find the vehicle at the station.',
                    $missing,
                    __('are still missing')
                )
        );
    }

    /**
     * Is this an order the gate applies to, in the state it applies at?
     */
    private function requiresConsignment(OrderInterface $order): bool
    {
        if ((string) $order->getStatus() !== DeliveryStatus::OUT_FOR_DELIVERY) {
            return false;
        }

        // Already there before this save, so this save is not the dispatch —
        // it is something else touching an order that happens to be out for
        // delivery, and refusing it is what broke a customer's email change.
        // See "IT GATES THE TRANSITION" in the header.
        if ($this->wasAlreadyOutForDelivery($order)) {
            return false;
        }

        return $this->pickup->getType($order) === PickupType::DEPOT;
    }

    /**
     * Did the order ALREADY hold this status when it was loaded?
     *
     * `getOrigData()` is the snapshot AbstractModel takes on load, so by the
     * time beforeSave() dispatches this there is nothing to fetch — the answer
     * is already in memory.
     *
     * FALSE IS THE FAIL-SAFE ANSWER and every uncertain case returns it: no id
     * (the order is being placed), no snapshot at all (an integration built the
     * model rather than loading it), or a status this class cannot read. Those
     * are all treated as "this save is setting the status", which is what keeps
     * the gate closed on the paths it was written for.
     */
    private function wasAlreadyOutForDelivery(OrderInterface $order): bool
    {
        if (!$order instanceof AbstractModel || !$order->getId()) {
            return false;
        }

        $previous = $order->getOrigData(OrderInterface::STATUS);

        return is_string($previous) && $previous === DeliveryStatus::OUT_FOR_DELIVERY;
    }

    /**
     * The order's consignment, or `false` for "there is nothing to gate here".
     *
     * Three-valued on purpose, because null and "not applicable" are different
     * answers and the caller phrases a different message for each:
     *
     *   ConsignmentInterface  a row exists; the requirements decide
     *   null                  no row at all; every field is missing
     *   false                 the order has no id, so it is being PLACED — it
     *                         cannot already be out for delivery and there is
     *                         nothing to look up
     *
     * @return ConsignmentInterface|null|false
     */
    private function findConsignment(OrderInterface $order): ConsignmentInterface|null|false
    {
        $orderId = (int) $order->getEntityId();

        if ($orderId === 0) {
            return false;
        }

        return $this->consignments->getByOrderId($orderId);
    }
}
