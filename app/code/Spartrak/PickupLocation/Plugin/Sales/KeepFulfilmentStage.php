<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Plugin\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Handler\State as StateHandler;
use Spartrak\PickupLocation\Model\DeliveryStatus;

/**
 * KEEPS THE FULFILMENT STAGE WHEN MAGENTO MOVES THE COMMERCIAL STATE.
 *
 * ===========================================================================
 * THE TWO AXES, AND WHAT KEPT COLLAPSING THEM
 * ===========================================================================
 * §2 of the spec is built on the two axes being separate:
 *
 *   Axis A  commercial state    is it accepted, paid, shipped?   Magento's
 *   Axis B  fulfilment stage    where are the goods?             ours
 *
 * Magento stores Axis B in the same column as its own summary of Axis A — the
 * order's `status` — and `ResourceModel\Order\Handler\State::check()` runs on
 * EVERY order save. When it decides the state has moved it does this:
 *
 *     $order->setState(Order::STATE_COMPLETE)
 *           ->setStatus($order->getConfig()->getStateDefaultStatus(...));
 *
 * i.e. it OVERWRITES the status with the new state's default. So the moment an
 * admin raised the shipment for an order that was `شحنتك في الطريق اليك`, the
 * order became `complete` / "Complete" and the fulfilment stage was gone —
 * with it, the customer's rail (which reads the status) and the reason the
 * driver card was visible. The same thing happens on invoice, from `new` to
 * `processing`.
 *
 * There is no way to stop that from an observer: `sales_order_save_before` is
 * dispatched by `AbstractModel::beforeSave()`, which runs BEFORE the resource's
 * `_beforeSave()` calls this handler. By the time any observer could look, the
 * status is already the state's default and the original is unrecoverable.
 *
 * ===========================================================================
 * SO IT IS A before/after PAIR ON THE HANDLER ITSELF
 * ===========================================================================
 * `beforeCheck` records the status the caller INTENDED — whatever the admin
 * chose, or whatever the order already carried. `afterCheck` puts it back if
 * the handler replaced it, and only if the station is legal in the state the
 * handler settled on (Model\DeliveryStatus::isAllowedInState, which reads the
 * same table the setup patch assigns from).
 *
 * Capturing the intent BEFORE is what makes this safe. The naive version reads
 * `getOrigData('status')` afterwards, and that is the PREVIOUS status, not the
 * intended one — so an admin deliberately moving an order from
 * `out for delivery` to `delivered` would have their choice reverted to
 * `out for delivery`, which is worse than the bug it was fixing.
 *
 * ===========================================================================
 * IT NEVER FIGHTS A LEGITIMATE COMMERCIAL TRANSITION
 * ===========================================================================
 * Only the STATUS is restored, never the state. An order that Magento decides
 * is `complete` stays `complete`; it simply keeps saying `شحنتك في الطريق اليك`
 * while the parcel is genuinely still in a vehicle. That is the correct
 * reading of both axes at once, and it is why `spartrak_out_for_delivery` is
 * assigned to `complete` as well as to `processing`.
 *
 * And it only ever restores one of THIS module's four statuses. A core status
 * is left entirely to core.
 */
class KeepFulfilmentStage
{
    /**
     * The status the caller wanted, for the duration of one check() call.
     *
     * Held on the plugin rather than passed through, because a `before` plugin
     * cannot hand a value to its `after` counterpart any other way. `check()`
     * does not recurse — it reads the order's invoice collection and nothing
     * that saves — so one slot is enough.
     */
    private ?string $intended = null;

    /**
     * @param StateHandler $subject
     * @param Order $order
     * @return array{0: Order}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeCheck(StateHandler $subject, Order $order): array
    {
        $this->intended = (string) $order->getStatus();

        return [$order];
    }

    /**
     * @param StateHandler $subject
     * @param StateHandler $result
     * @param Order $order
     * @return StateHandler
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCheck(StateHandler $subject, $result, Order $order)
    {
        $intended = $this->intended;
        $this->intended = null;

        if (!DeliveryStatus::isSpartrakStatus($intended)) {
            return $result;
        }

        if ((string) $order->getStatus() === $intended) {
            // The handler left it alone, which is the ordinary case.
            return $result;
        }

        if (!DeliveryStatus::isAllowedInState($intended, (string) $order->getState())) {
            /**
             * The state the handler settled on is one this station may not
             * stand in — `canceled`, `closed`, `holded`. Restoring it would
             * put the order in a status/state pair the admin's own dropdown
             * would then refuse to show, and §6 handles each of those three
             * cases in its own right. Core's answer stands.
             */
            return $result;
        }

        $order->setStatus($intended);

        return $result;
    }
}
