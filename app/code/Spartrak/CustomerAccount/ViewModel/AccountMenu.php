<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAccount\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The signed-in shopper's destinations, in one place.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 * The same list is drawn twice: the desktop header's account menu
 * (Figma 562:11002, rendered by Magento_Theme::html/account-chip.phtml) and the
 * mobile drawer's account disclosure (645:39359, rendered by
 * Magento_Theme::html/mobile-nav-drawer.phtml).
 *
 * It used to be a literal array inside account-chip.phtml, and the drawer was
 * about to grow a second copy of it. Two copies of a navigation list is how one
 * of them quietly loses a destination — CLAUDE.md section 9's "duplicate
 * business logic across components", applied to content. One declaration, two
 * consumers, exactly as the theme already does for brand navigation.
 *
 * ===========================================================================
 * WHY A VIEW MODEL AND NOT LAYOUT XML
 * ===========================================================================
 * Layout could declare five link blocks, and for the ACCOUNT PAGE's rail that
 * is what happens — those are real SortLink blocks so third parties can add to
 * them (see the theme's customer_account.xml). These two surfaces are different:
 * both render inside a single ttl-cached header block, both need the list as
 * DATA to iterate rather than as children to render, and neither has a
 * container a module could hang a block on. A view model is the honest shape
 * for "hand me this list".
 *
 * ===========================================================================
 * LOGOUT IS SEPARATE, ON PURPOSE
 * ===========================================================================
 * Both designs pin it below the others, in the danger ramp, visually apart —
 * desktop 562:11021, mobile 645:39394. Returning it inside the same array would
 * make every consumer re-discover which entry is special.
 */
class AccountMenu implements ArgumentInterface
{
    /**
     * route => label, icon modifier.
     *
     * The modifier selects the mask in CSS rather than naming an asset here;
     * see the theme's foundations/_icons.less for why every icon in this
     * project is a mask and never an inline background-image.
     *
     * `contact` is Magento's own contact-us route. It is the support
     * destination the storefront already uses in the footer and the utility
     * strip, so the account menu points at the same place rather than
     * inventing a help page.
     *
     * @var array<int, array{route: string, label: string, modifier: string}>
     */
    private const LINKS = [
        ['route' => 'customer/account',    'label' => 'My account',       'modifier' => 'user'],
        ['route' => 'sales/order/history', 'label' => 'My orders',        'modifier' => 'orders'],
        ['route' => 'customer/address',    'label' => 'My addresses',     'modifier' => 'addresses'],
        ['route' => 'wishlist',            'label' => 'My wishlist',      'modifier' => 'wishlist'],
        ['route' => 'contact',             'label' => 'Help and support', 'modifier' => 'support'],
    ];

    private const LOGOUT_ROUTE = 'customer/account/logout';

    public function __construct(
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @return array<int, array{url: string, label: \Magento\Framework\Phrase, modifier: string}>
     */
    public function getLinks(): array
    {
        $links = [];

        foreach (self::LINKS as $link) {
            $links[] = [
                'url' => $this->url->getUrl($link['route']),
                'label' => __($link['label']),
                'modifier' => $link['modifier'],
            ];
        }

        return $links;
    }

    /**
     * @return array{url: string, label: \Magento\Framework\Phrase, modifier: string}
     */
    public function getLogoutLink(): array
    {
        return [
            // A plain GET url. Magento\Customer\Controller\Account\Logout
            // implements HttpGetActionInterface and the platform guards it with
            // its own secret key, so there is no form to post.
            'url' => $this->url->getUrl(self::LOGOUT_ROUTE),
            'label' => __('Sign out'),
            'modifier' => 'logout',
        ];
    }
}
