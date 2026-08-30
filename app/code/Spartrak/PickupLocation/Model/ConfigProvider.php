<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\PickupLocation\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Spartrak\PickupLocation\Model\Carrier\BranchPickup;
use Spartrak\PickupLocation\Model\Carrier\DepotPickup;

/**
 * Publishes the pickup networks into window.checkoutConfig.
 *
 * ===========================================================================
 * WHY THE LISTS ARE INLINED RATHER THAN FETCHED
 * ===========================================================================
 * A branch list is a few dozen rows of short strings. Inlining it costs a few
 * kilobytes in a payload the page already downloads; fetching it costs a round
 * trip AFTER the shopper taps the segment, which is a visible stall at the
 * exact moment they are deciding. CLAUDE.md section 13 ranks fewer network
 * requests above smaller assets, and this is that trade made deliberately.
 *
 * If the depot network ever grows past a few hundred rows this stops being
 * true - the honest threshold is when the serialized list gets past roughly
 * 50KB, at which point the depot list (which already has a search box in the
 * design) should move behind an endpoint and the branch list should not.
 *
 * ===========================================================================
 * WHAT IS NOT HERE
 * ===========================================================================
 * No prices and no labels for the segmented control. Those are RATE data, and
 * the checkout already receives them through the shipping-methods payload -
 * publishing them a second time would be two sources for one fact. The
 * storefront reads the segment's label off the rate it belongs to.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly LocationCatalog $locationCatalog,
        private readonly CheckoutSession $checkoutSession,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'spartrakPickup' => [
                'branch' => [
                    'carrier' => BranchPickup::CODE,
                    'method' => BranchPickup::METHOD,
                    'locations' => $this->locationCatalog->getBranches(),
                ],
                'depot' => [
                    'carrier' => DepotPickup::CODE,
                    'method' => DepotPickup::METHOD,
                    'locations' => $this->locationCatalog->getDepots(),
                    'operators' => $this->locationCatalog->getOperators(),
                    'disclaimer' => $this->depotDisclaimer(),
                ],
                // So a shopper who reloads mid-checkout sees their own choice
                // still selected rather than an empty list.
                'selected' => $this->currentSelection(),
            ],
        ];
    }

    /**
     * The footnote under the depot list, from configuration.
     *
     * Figma draws one (554:13750); its WORDS are a merchant statement about a
     * third party's service, so they are configuration rather than a string
     * frozen into a template. An empty value hides the line entirely.
     */
    private function depotDisclaimer(): string
    {
        return trim((string) $this->scopeConfig->getValue(
            'carriers/' . DepotPickup::CODE . '/disclaimer',
            ScopeInterface::SCOPE_STORE
        ));
    }

    /**
     * The location already on the quote, if any.
     *
     * @return array{type: string, id: int}|null
     */
    private function currentSelection(): ?array
    {
        $quote = $this->checkoutSession->getQuote();

        if ($quote->getIsVirtual()) {
            return null;
        }

        $address = $quote->getShippingAddress();
        $type = $address->getData('spartrak_pickup_type');
        $id = (int) $address->getData('spartrak_pickup_id');

        if (!is_string($type) || !PickupType::isValid($type) || $id <= 0) {
            return null;
        }

        return ['type' => $type, 'id' => $id];
    }
}
