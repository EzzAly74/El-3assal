<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Model\Sms;

use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Api\SmsGatewayInterface;
use Spartrak\CustomerAuth\Model\Config;
use Spartrak\CustomerAuth\Model\Sms\Gateway\LogGateway;

/**
 * Picks the configured gateway out of the di.xml-registered pool.
 *
 * The pool is injected rather than built with an ObjectManager lookup so an
 * unknown gateway code is a config problem, not a class-not-found crash, and so
 * `di:compile` can see every driver.
 */
class GatewayResolver
{
    /**
     * @param array<string, SmsGatewayInterface> $gateways Keyed by config code.
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly array $gateways = []
    ) {
    }

    /**
     * Resolve the gateway for a store view.
     *
     * Falls back to the log driver on an unknown code instead of throwing. A
     * typo in config must not take the storefront down with a 500 on every
     * sign-in attempt; it should fail loudly in the log and visibly in staging
     * (isRealDelivery() === false) while the site stays up.
     */
    public function resolve(?int $storeId = null): SmsGatewayInterface
    {
        $code = $this->config->getSmsGatewayCode($storeId);
        $gateway = $this->findByCode($code);

        if ($gateway !== null) {
            return $gateway;
        }

        $this->logger->critical(
            sprintf(
                'Spartrak_CustomerAuth: SMS gateway "%s" is configured but not registered in di.xml. '
                . 'Falling back to the log driver — NO SMS WILL BE DELIVERED. Registered codes: %s',
                $code,
                implode(', ', array_keys($this->gateways)) ?: '(none)'
            )
        );

        return $this->findByCode(LogGateway::CODE) ?? new LogGateway($this->logger);
    }

    /**
     * @return array<string, SmsGatewayInterface> Keyed by config code.
     */
    public function getAvailableGateways(): array
    {
        $available = [];

        foreach ($this->gateways as $code => $gateway) {
            if ($gateway instanceof SmsGatewayInterface) {
                $available[(string) $code] = $gateway;
            }
        }

        return $available;
    }

    private function findByCode(string $code): ?SmsGatewayInterface
    {
        $gateway = $this->gateways[$code] ?? null;

        return $gateway instanceof SmsGatewayInterface ? $gateway : null;
    }
}
