<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Cron;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\ResourceModel\OtpRequest as OtpRequestResource;

/**
 * Deletes spent OTP rows.
 *
 * This is a data-retention job, not table hygiene. Every row holds a phone
 * number, which is personal data the store has no reason to keep once the code it
 * belonged to is dead. Left alone the ledger grows one row per sign-up attempt
 * forever and becomes a standing liability in every backup.
 *
 * Live rows (pending/verified) are never touched regardless of age, so the job
 * cannot break a registration that is mid-flight when it runs.
 */
class PurgeExpiredOtpRequests
{
    public function __construct(
        private readonly Config $config,
        private readonly OtpRequestResource $otpRequestResource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $cutoff = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - ($this->config->getPurgeAfterDays() * 86400)
        );

        try {
            $deleted = $this->otpRequestResource->purgeExpiredBefore($cutoff);
        } catch (\Throwable $e) {
            // Never let this bring the cron group down — the rest of the group
            // has nothing to do with OTP retention.
            $this->logger->error(
                'Spartrak_CustomerAuth: OTP purge failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return;
        }

        if ($deleted > 0) {
            $this->logger->info(
                sprintf('Spartrak_CustomerAuth: purged %d expired OTP records older than %s.', $deleted, $cutoff)
            );
        }
    }
}
