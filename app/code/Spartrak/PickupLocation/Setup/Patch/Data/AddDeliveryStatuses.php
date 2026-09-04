<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;

/**
 * Creates the four fulfilment statuses the tracking stepper runs on and
 * assigns each to its state.
 *
 * Stores > Order Status shows only Magento's own thirteen on a fresh install
 * (verified on this project's admin), so all four are new rows.
 *
 * ===========================================================================
 * WHY A DATA PATCH AND NOT A MANUAL ADMIN STEP
 * ===========================================================================
 * The gate in Plugin\Sales\RequireConsignmentBeforeDispatch matches on
 * DeliveryStatus::OUT_FOR_DELIVERY. If that status only exists because someone
 * typed it into the admin on one environment, then on every other environment
 * the gate matches nothing, the status is simply absent from the dropdown, and
 * the whole feature silently does not exist. A status the code depends on is
 * schema, not configuration.
 *
 * ===========================================================================
 * IDEMPOTENT, AND NON-DESTRUCTIVE ABOUT LABELS
 * ===========================================================================
 * A data patch runs once per install, but it also has to survive being
 * re-applied on an environment where the statuses were created by hand first —
 * and it must not overwrite a label a merchant has since edited. So each status
 * is created only when absent, and an existing one is left exactly as it is.
 * Only the state ASSIGNMENT is re-asserted, because without it the status does
 * not appear in the order view's dropdown at all and the feature is unusable.
 */
class AddDeliveryStatuses implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly StatusFactory $statusFactory,
        private readonly StatusResource $statusResource,
        private readonly StatusCollectionFactory $statusCollectionFactory
    ) {
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
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

        foreach (\Spartrak\PickupLocation\Model\DeliveryStatus::all() as $code => $definition) {
            /** @var Status $status */
            $status = $existing[$code] ?? null;

            if ($status === null) {
                $status = $this->statusFactory->create();
                $status->setData([
                    'status' => $code,
                    'label' => $definition['label'],
                ]);
                $this->statusResource->save($status);
            }

            // Re-asserted on every apply: an unassigned status is invisible in
            // the order view's status dropdown, which would make the whole
            // feature unreachable. `false` for isDefault — see
            // Model\DeliveryStatus for why none of these may become a state's
            // default.
            $status->assignState($definition['state'], false, true);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }
}
