<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Spartrak\PickupLocation\Api\Data\ConsignmentInterface;

/**
 * Reads and writes the driver/vehicle record for an order.
 *
 * Deliberately narrow: an order has at most one consignment (unique index on
 * order_id), so there is no list, no search criteria and no delete. The two
 * questions anyone asks are "does this order have one?" and "save this one",
 * and offering more would be API surface with no caller.
 *
 * getByOrderId() returns null rather than throwing NoSuchEntityException. The
 * absence of a consignment is the NORMAL state for most of an order's life —
 * BUSINESS.md section 12, §6: hidden through `بانتظار الموافقة` and
 * `تم التعبئة` because the data does not exist yet — so it is not an
 * exceptional condition and the storefront asks the question on every order
 * page render.
 */
interface ConsignmentRepositoryInterface
{
    public function getByOrderId(int $orderId): ?ConsignmentInterface;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ConsignmentInterface $consignment): ConsignmentInterface;
}
