<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The instalment-plans panel under the cart summary (Figma "Gift Option
 * Container" 817:22748).
 *
 * ===========================================================================
 * WHY CONFIGURATION AND NOT A CMS BLOCK OR A TABLE
 * ===========================================================================
 * It is admin-managed content, which CLAUDE.md section 7 requires - a merchant
 * changes "6 months interest free" to "12 months" without a deploy, per store
 * view, in Arabic and English.
 *
 * Not a CMS block: the brief rules those out for checkout, and a free-form HTML
 * field would let the panel's structure drift away from the design the moment
 * someone pasted a table into it. The fields here are a defined schema -
 * heading, body, note - and the theme owns how each is rendered.
 *
 * Not a table either: there is exactly ONE panel. Cardinality one is what
 * separates a config group from an entity, and the same reasoning is written up
 * on Spartrak_Homepage's promo columns.
 *
 * The illustration is a fixed brand asset from Figma (817:22754), so it lives
 * in the theme like every other design asset - it is not content a merchant
 * edits, and section 3 requires the exact Figma file rather than an upload that
 * might not be it.
 */
class InstalmentsPromo implements ArgumentInterface
{
    private const PATH = 'checkout/spartrak_instalments/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The panel renders only when it is switched on AND has a heading.
     *
     * Both, because an enabled-but-empty panel is a box of whitespace in the
     * middle of the cart - a state a merchant can reach in one click, so it is
     * handled here rather than left to look like a bug.
     */
    public function isVisible(): bool
    {
        return $this->flag('enabled') && $this->getHeading() !== null;
    }

    public function getHeading(): ?string
    {
        return $this->value('heading');
    }

    public function getBody(): ?string
    {
        return $this->value('body');
    }

    /**
     * The panel's call to action - Figma's third line, `اضغط هنا`.
     *
     * It replaced a plain footnote. A sentence naming the providers told a
     * shopper the plans existed and then left them with nowhere to go; the
     * design's third line is something to press.
     */
    public function getCtaLabel(): ?string
    {
        return $this->value('cta_label');
    }

    /**
     * Where that button goes, or null while the merchant has not said.
     *
     * NULL IS A REAL STATE, NOT AN OVERSIGHT. The instalment providers are a
     * commercial arrangement this store has not finished making, so there is no
     * URL to ship a default for - and inventing one would send shoppers
     * somewhere on the strength of a guess. Until it is set the template
     * renders the control as an inert button: the design is complete, the
     * destination is not, and the difference is visible to the merchant in the
     * admin rather than hidden in a template.
     */
    public function getCtaUrl(): ?string
    {
        return $this->value('cta_url');
    }

    private function flag(string $field): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . $field, ScopeInterface::SCOPE_STORE);
    }

    private function value(string $field): ?string
    {
        $value = trim((string) $this->scopeConfig->getValue(
            self::PATH . $field,
            ScopeInterface::SCOPE_STORE
        ));

        return $value !== '' ? $value : null;
    }
}
