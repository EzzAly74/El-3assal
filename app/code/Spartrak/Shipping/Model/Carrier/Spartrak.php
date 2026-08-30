<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Shipping\Model\Carrier;

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

/**
 * The SpareTrak delivery carrier — two tiers, both merchant-configured.
 *
 * ===========================================================================
 * WHY A CARRIER AND NOT TWO CORE ONES
 * ===========================================================================
 * Figma's shipping step (549:26260) draws exactly two options:
 *
 *     شحن عادي      ٥–٧ أيام عمل    0.00 ج.م (مجانا)
 *     شحن اكسبريس   ١–٣ أيام عمل    +50.00 ج.م
 *
 * Core could nearly do this with `freeshipping` + `flatrate`. Nearly, and the
 * gap is the part the design is actually about: neither core carrier can carry
 * a DELIVERY WINDOW, and the window is half of what the card shows. Bolting the
 * days onto a method title turns data into a sentence — see Model\DeliveryWindow
 * for why that is rejected.
 *
 * One carrier with two methods also keeps the pair together in the admin, where
 * a merchant reasons about "our delivery options", not about two unrelated
 * modules that happen to render side by side.
 *
 * ===========================================================================
 * NOTHING HERE IS HARDCODED FOR THE STOREFRONT
 * ===========================================================================
 * Titles, prices and windows are all config. The values in etc/config.xml are
 * DEFAULTS that reproduce the Figma frame on a fresh install; a merchant may
 * rename, re-price or disable either tier per store view without a deploy, and
 * the checkout renders whatever the rates actually say (CLAUDE.md §5, §7).
 *
 * The free tier is priced at 0 by configuration rather than by a `freeshipping`
 * special case, so a merchant who later charges for standard delivery simply
 * types a number.
 */
class Spartrak extends AbstractCarrier implements CarrierInterface
{
    public const CODE = 'spartrak';

    /** The two tiers the design draws. Method codes are stable identifiers. */
    public const METHOD_STANDARD = 'standard';
    public const METHOD_EXPRESS = 'express';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * Both tiers are flat prices, not calculated per package.
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
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * The method codes this carrier can return, keyed by their configured title.
     *
     * Magento calls this for the admin order-create screen and for sales rules,
     * so it must reflect the same enabled/disabled state as collectRates().
     *
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        $allowed = [];

        foreach ([self::METHOD_STANDARD, self::METHOD_EXPRESS] as $method) {
            if ($this->isMethodEnabled($method)) {
                $allowed[$method] = $this->methodTitle($method);
            }
        }

        return $allowed;
    }

    /**
     * @param RateRequest $request
     * @return Result|bool false when the carrier is switched off entirely
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();

        foreach ([self::METHOD_STANDARD, self::METHOD_EXPRESS] as $method) {
            if ($this->isMethodEnabled($method)) {
                $result->append($this->buildRate($method));
            }
        }

        return $result;
    }

    /**
     * One rate row.
     *
     * `price` and `cost` are set from the same figure deliberately: this is a
     * flat published charge, not a marked-up purchased service, so there is no
     * separate cost to record. Setting cost to 0 instead would misreport margin
     * on every shipping line in the sales reports.
     */
    private function buildRate(string $methodCode): Method
    {
        $price = $this->methodPrice($methodCode);

        /** @var Method $rate */
        $rate = $this->rateMethodFactory->create();

        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title'));
        $rate->setMethod($methodCode);
        $rate->setMethodTitle($this->methodTitle($methodCode));
        $rate->setPrice($price);
        $rate->setCost($price);

        return $rate;
    }

    private function isMethodEnabled(string $methodCode): bool
    {
        // A missing flag means enabled: the defaults in config.xml switch both
        // tiers on, and an upgrade that adds a third tier should not silently
        // disable it for stores that have never seen the field.
        $value = $this->getConfigData($methodCode . '_enabled');

        return $value === null || $value === '' || (bool) $value;
    }

    private function methodTitle(string $methodCode): string
    {
        $title = (string) $this->getConfigData($methodCode . '_title');

        // Never render an empty label. Falling back to the code is ugly on
        // purpose — it is visible in QA, where a blank title is not.
        return $title !== '' ? $title : $methodCode;
    }

    private function methodPrice(string $methodCode): float
    {
        return (float) $this->getConfigData($methodCode . '_price');
    }
}
