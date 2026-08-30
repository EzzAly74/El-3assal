<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use Spartrak\PickupLocation\Model\LocationCatalog;

/**
 * What both pickup carriers do identically.
 *
 * ===========================================================================
 * WHY TWO CARRIERS AND NOT ONE WITH TWO METHODS
 * ===========================================================================
 * Spartrak_Shipping is one carrier with two methods, because standard and
 * express are two prices for one service. Branch and depot are NOT that: they
 * are two different fulfilment networks with different data behind them,
 * different admin owners, and different storefront flows (a branch list, versus
 * a searchable depot list with operator chips). A merchant may run branch
 * pickup and not depot pickup, or price them differently, and must be able to
 * switch one off without touching the other.
 *
 * Two carriers also mean each appears as its own row in Stores > Shipping
 * Methods, which is where a merchant looks for exactly this switch.
 *
 * ===========================================================================
 * A PICKUP CARRIER THAT CANNOT BE COLLECTED FROM DOES NOT OFFER ITSELF
 * ===========================================================================
 * collectRates() returns false when the location list is empty. Offering
 * "collect from a branch" to a shopper who will then be shown an empty list is
 * a dead end that costs an order; the delivery-mode control reads the same
 * signal and hides the segment entirely.
 */
abstract class AbstractPickup extends AbstractCarrier implements CarrierInterface
{
    /**
     * Pickup is a flat charge (normally zero), never calculated per package.
     *
     * @var bool
     */
    protected $_isFixed = true;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        protected readonly LocationCatalog $locationCatalog,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * The single method code this carrier publishes.
     */
    abstract public function getMethodCode(): string;

    /**
     * True when there is at least one enabled location of this carrier's kind.
     */
    abstract protected function hasLocations(): bool;

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return [$this->getMethodCode() => $this->methodTitle()];
    }

    /**
     * @param RateRequest $request
     * @return Result|bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        // See the class header: no locations, no offer.
        if (!$this->hasLocations()) {
            return false;
        }

        $price = (float) $this->getConfigData('price');

        /** @var Method $rate */
        $rate = $this->rateMethodFactory->create();
        $rate->setCarrier($this->getCarrierCode());
        $rate->setCarrierTitle((string) $this->getConfigData('title'));
        $rate->setMethod($this->getMethodCode());
        $rate->setMethodTitle($this->methodTitle());
        // Same figure into both, for the reason recorded on
        // Spartrak\Shipping\Model\Carrier\Spartrak::buildRate().
        $rate->setPrice($price);
        $rate->setCost($price);

        /** @var Result $result */
        $result = $this->rateResultFactory->create();
        $result->append($rate);

        return $result;
    }

    /**
     * Pickup is never "shipped" in the courier sense, so Magento should not
     * offer a tracking UI for it.
     */
    public function isTrackingAvailable(): bool
    {
        return false;
    }

    protected function getCarrierCode(): string
    {
        return (string) $this->_code;
    }

    private function methodTitle(): string
    {
        $title = (string) $this->getConfigData('name');

        return $title !== '' ? $title : $this->getMethodCode();
    }
}
