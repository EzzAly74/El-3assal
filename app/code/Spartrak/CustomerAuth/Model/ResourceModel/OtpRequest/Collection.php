<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\ResourceModel\OtpRequest;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Spartrak\CustomerAuth\Model\OtpRequest;
use Spartrak\CustomerAuth\Model\ResourceModel\OtpRequest as OtpRequestResource;

/**
 * Collection over the OTP ledger.
 *
 * Diagnostics only — every hot path in this module reads through the resource
 * model's aggregate helpers instead, so nothing here runs on a shopper request.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected $_idFieldName = 'request_id';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(OtpRequest::class, OtpRequestResource::class);
    }
}
