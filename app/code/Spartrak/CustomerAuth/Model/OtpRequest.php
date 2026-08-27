<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model;

use Magento\Framework\Model\AbstractModel;
use Spartrak\CustomerAuth\Model\Otp\Status;
use Spartrak\CustomerAuth\Model\ResourceModel\OtpRequest as OtpRequestResource;

/**
 * One issued OTP.
 *
 * Kept as a plain AbstractModel rather than a full repository/service-contract
 * stack on purpose: this entity is internal plumbing with no API surface, no
 * admin grid and no third-party consumers. A repository, SearchCriteria layer
 * and data interface would be four more files serving nobody. Everything
 * outside the module talks to Otp\Service, never to this.
 *
 * @method string getPhone()
 * @method string getPurpose()
 * @method string getCodeHash()
 * @method string|null getTokenHash()
 * @method int getAttempts()
 * @method string getStatus()
 * @method string|null getIpAddress()
 * @method int getStoreId()
 * @method string|null getExpiresAt()
 * @method string|null getTokenExpiresAt()
 * @method string getCreatedAt()
 */
class OtpRequest extends AbstractModel
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
        $this->_init(OtpRequestResource::class);
    }

    public function isPending(): bool
    {
        return $this->getStatus() === Status::PENDING;
    }

    public function isVerified(): bool
    {
        return $this->getStatus() === Status::VERIFIED;
    }
}
