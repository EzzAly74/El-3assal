<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactoryInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * The number in the sidebar's "طلباتي" badge — Figma 562:11010.
 *
 * ===========================================================================
 * WHY THIS COUNTS THE SAME THING THE ORDERS PAGE LISTS
 * ===========================================================================
 * The badge sits directly above a link to `sales/order/history`, so a shopper
 * reading "4" and then finding three rows has been lied to. Core's history
 * block builds its list from the customer's own order collection narrowed to
 * `Config::getVisibleOnFrontStatuses()`; this asks the same factory the same
 * question and takes `getSize()` instead of the rows.
 *
 * `getSize()` issues a COUNT(*) against the same filtered select rather than
 * hydrating order models — the whole point of using the collection here rather
 * than OrderRepository + SearchCriteria, which would build DTOs for rows nobody
 * renders.
 *
 * ===========================================================================
 * WHY THE COUNT IS MEMOISED
 * ===========================================================================
 * The sidebar renders on EVERY account page, and the link block that reads this
 * is instantiated once per page — but a template is free to ask twice (the
 * mobile tab strip and the desktop rail are two renders of the same nav on the
 * same page, see the theme's account/navigation.phtml). One query per request
 * either way.
 *
 * ===========================================================================
 * WHY THERE IS NO CACHE
 * ===========================================================================
 * Account pages are private: `customer_account` is served with the page cache
 * off for logged-in customers, so there is no shared cache entry this could
 * poison, and a per-customer cache tag invalidated on every order placement
 * would cost more to maintain than the COUNT it saves.
 */
class Navigation implements ArgumentInterface
{
    private ?int $orderCount = null;

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CollectionFactoryInterface $orderCollectionFactory,
        private readonly OrderConfig $orderConfig
    ) {
    }

    /**
     * How many of this customer's orders the storefront is willing to show.
     *
     * Zero for a guest — which cannot normally reach an account page, but the
     * badge must not fatal if one somehow does.
     */
    public function getOrderCount(): int
    {
        if ($this->orderCount !== null) {
            return $this->orderCount;
        }

        $customerId = $this->customerSession->getCustomerId();

        if (!$customerId) {
            return $this->orderCount = 0;
        }

        $this->orderCount = (int) $this->orderCollectionFactory
            ->create((int) $customerId)
            ->addFieldToFilter('status', ['in' => $this->orderConfig->getVisibleOnFrontStatuses()])
            ->getSize();

        return $this->orderCount;
    }

    /**
     * Figma draws no empty badge — a customer with no orders gets the plain
     * row. Asked by the template so the decision is stated once, here, rather
     * than as a bare `> 0` in markup.
     */
    public function hasOrders(): bool
    {
        return $this->getOrderCount() > 0;
    }
}
