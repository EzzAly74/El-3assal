<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Everything this module reads out of Stores > Configuration, typed.
 *
 * Nothing else in the module touches ScopeConfigInterface — the same rule
 * Spartrak\CustomerAuth\Model\Config and Spartrak\Contact\Model\Config follow,
 * for the same reason: a config path written in two places is a config path
 * that will eventually be read from one of them after someone renames it.
 *
 * ===========================================================================
 * THE PLAYBACK VALUES ARE DEFAULTS, NOT SETTINGS
 * ===========================================================================
 * `getDefaultAutoplay()` and its three siblings answer "what should a video
 * that has expressed no opinion do?". A video that HAS expressed one carries a
 * 0 or a 1 in its own column and never consults these. See
 * Model\Video\Settings::resolve(), which is where the two meet — deliberately
 * one place, so the precedence rule cannot differ between the template that
 * renders a player and the block that decides whether to ship any JS at all.
 *
 * Every getter takes a store id and reads at store scope, because this
 * storefront is bilingual and a merchant may reasonably want different
 * defaults per store view.
 */
class Config
{
    private const PATH_PREFIX = 'spartrak_product_video/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The master switch. When this is off the frontend block renders nothing
     * at all — no markup, no JS, no CSS request — and the admin dialog's extra
     * fields disappear with it.
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag('general/enabled', $storeId);
    }

    public function getDefaultAutoplay(?int $storeId = null): bool
    {
        return $this->flag('playback/autoplay', $storeId);
    }

    public function getDefaultLoop(?int $storeId = null): bool
    {
        return $this->flag('playback/loop', $storeId);
    }

    /**
     * Defaults to ON in etc/config.xml, and that is not a style choice: every
     * browser blocks autoplay with sound, so an autoplaying video that is not
     * muted simply does not start. Muted-by-default is what makes the autoplay
     * switch above mean anything.
     */
    public function getDefaultMuted(?int $storeId = null): bool
    {
        return $this->flag('playback/muted', $storeId);
    }

    public function getDefaultControls(?int $storeId = null): bool
    {
        return $this->flag('playback/controls', $storeId);
    }

    public function getDefaultAllowDownload(?int $storeId = null): bool
    {
        return $this->flag('storefront/allow_download', $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_PREFIX . $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
