<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Spartrak\PickupLocation\Api\ConsignmentRepositoryInterface;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;
use Spartrak\PickupLocation\Model\ResourceModel\Consignment as ConsignmentResource;

/**
 * @see ConsignmentRepositoryInterface for why this is deliberately narrow.
 */
class ConsignmentRepository implements ConsignmentRepositoryInterface
{
    /**
     * Per-request cache, keyed by order id.
     *
     * The storefront order page asks for the consignment several times in one
     * render — the card, the reassurance strip's wording, and the tracker's
     * gate on whether the card exists at all — and the admin gate asks again on
     * save. `false` marks "asked, and there is none", so an order without a
     * consignment is not re-queried either.
     *
     * @var array<int, ConsignmentInterface|false>
     */
    private array $byOrder = [];

    public function __construct(
        private readonly ConsignmentFactory $consignmentFactory,
        private readonly ConsignmentResource $resource
    ) {
    }

    public function getByOrderId(int $orderId): ?ConsignmentInterface
    {
        if ($orderId === 0) {
            return null;
        }

        if (array_key_exists($orderId, $this->byOrder)) {
            return $this->byOrder[$orderId] ?: null;
        }

        $consignment = $this->consignmentFactory->create();
        $this->resource->load($consignment, $orderId, ConsignmentInterface::ORDER_ID);

        $found = $consignment->getConsignmentId() !== null ? $consignment : false;
        $this->byOrder[$orderId] = $found;

        return $found ?: null;
    }

    public function save(ConsignmentInterface $consignment): ConsignmentInterface
    {
        try {
            $this->resource->save($consignment);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('We could not save the driver and vehicle details.'),
                $e
            );
        }

        // The cache would otherwise hand the dispatch gate the pre-save state,
        // and the gate runs in the same request as the save on the admin's
        // second submit.
        unset($this->byOrder[$consignment->getOrderId()]);

        return $consignment;
    }
}
