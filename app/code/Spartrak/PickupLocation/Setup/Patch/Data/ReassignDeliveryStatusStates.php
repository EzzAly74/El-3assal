<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use Spartrak\PickupLocation\Model\DeliveryStatus;

/**
 * Re-assigns the four fulfilment statuses to EVERY state they may stand in.
 *
 * ===========================================================================
 * THE DEFECT THIS FIXES
 * ===========================================================================
 * `AddDeliveryStatuses` assigned each status to ONE state, and
 * `spartrak_delivered`'s one state was `complete`. Every invoiced, unshipped
 * order sits in `processing` — so the last station of the rail was unreachable:
 *
 *   Magento builds the order view's status dropdown from
 *   `getStateStatuses($order->getState())`, and
 *   Controller\Adminhtml\Order\AddComment SILENTLY DISCARDS a posted status
 *   that is not assigned to the current state — `getOrderStatus()` returns the
 *   order's existing status instead of raising anything.
 *
 * So the dropdown on a `موقف` order offered Processing, Suspected Fraud, Packed
 * and Out for delivery, and there was no way to mark the order collected and no
 * error explaining why. Model\DeliveryStatus now carries the full list of
 * states per station, with the reasoning for each.
 *
 * ===========================================================================
 * WHY A SECOND PATCH AND NOT AN EDIT TO THE FIRST
 * ===========================================================================
 * A data patch is recorded by class name in `patch_list` and never runs again.
 * Editing `AddDeliveryStatuses` would fix new installations and leave every
 * existing one exactly as broken as it is now — which is the environment this
 * bug was found on. A new class is the only thing that runs.
 *
 * ===========================================================================
 * IDEMPOTENT, AND IT ONLY ADDS
 * ===========================================================================
 * `Status::assignState()` writes through `insertOnDuplicate`, so re-asserting a
 * pair that already exists is a no-op. Nothing is UNassigned: a merchant who
 * has assigned one of these statuses to a further state of their own keeps it,
 * because removing an assignment could take a status out from under an order
 * currently carrying it.
 *
 * `false` for isDefault throughout — see Model\DeliveryStatus for why none of
 * these may become a state's default status.
 */
class ReassignDeliveryStatusStates implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly StatusCollectionFactory $statusCollectionFactory
    ) {
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [
            // The statuses themselves have to exist before they can be
            // re-assigned.
            AddDeliveryStatuses::class,
        ];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $existing = [];

        foreach ($this->statusCollectionFactory->create() as $status) {
            $existing[(string) $status->getStatus()] = $status;
        }

        foreach (DeliveryStatus::all() as $code => $definition) {
            /** @var Status|null $status */
            $status = $existing[$code] ?? null;

            if ($status === null) {
                // AddDeliveryStatuses should have created it; if a merchant
                // deleted one, the dependency patch will not run again to
                // recreate it and there is nothing here to assign.
                continue;
            }

            foreach ($definition['states'] as $state) {
                $status->assignState($state, false, true);
            }
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
