<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Checkout\Plugin\Checkout;

use Magento\Checkout\Block\Cart\Item\Renderer;
use Magento\Msrp\Helper\Data as MsrpHelper;
use Spartrak\Checkout\ViewModel\CartItemPricing;
use Spartrak\Checkout\ViewModel\CartQtyOptions;

/**
 * Hands every cart-line renderer the three collaborators its template reads.
 *
 * ===========================================================================
 * WHY THIS IS A PLUGIN AND NOT `<argument name="data">` IN di.xml
 * ===========================================================================
 * It WAS a di.xml `data` argument on Magento\Checkout\Block\Cart\Item\Renderer,
 * and that silently did nothing. This is the bug behind the cart line rendering
 * its quantity as dead text: `getData('qty_options_view_model')` was null on
 * every line, the template fell through to its "one quantity is not a choice"
 * branch, and no <select> was ever emitted for the stepper widget to enhance.
 * `pricing_view_model` and `msrp_helper` were null for the same reason, so the
 * struck was-price never rendered either.
 *
 * The mechanism, traced through core:
 *
 *   View\Layout\Generator\Block::generateBlock() builds every layout block as
 *       $this->createBlock($class, $name, ['data' => $evaluatedArguments])
 *
 *   BlockFactory::createBlock() forwards that to
 *       $this->objectManager->create($blockName, $arguments)
 *
 *   ObjectManager\Factory\*::_resolveArguments() merges di.xml's arguments with
 *   the runtime ones using
 *       array_replace($defaultArguments, $arguments)
 *
 * `array_replace` is SHALLOW and `data` is a single key. Layout always supplies
 * that key — as an empty array when the block declares no <arguments> — so the
 * runtime value replaces the configured one wholesale. A di.xml `data` array is
 * therefore unreachable for any block created by layout, which is every block
 * on a page. It is a real Magento trap: the configuration is valid, compiles
 * cleanly, and is discarded at construction with no warning.
 *
 * ===========================================================================
 * WHY NOT LAYOUT XML, WHICH IS THE USUAL HOME FOR A VIEW MODEL
 * ===========================================================================
 * Because there is no single block to name. Magento resolves a cart item
 * renderer PER PRODUCT TYPE — default, simple, virtual, configurable, grouped,
 * bundle, downloadable — and a <referenceBlock> reaches exactly one of them. A
 * hand-written list of seven is what this file's neighbours already do for the
 * wishlist removals, and there it is safe: a `remove` on a name that does not
 * exist is a no-op. Here it is not safe. A product type installed later gets no
 * view model, and its cart line silently reverts to the dead-text control this
 * plugin exists to fix. That failure is invisible until a shopper meets it.
 *
 * A plugin declared on the BASE renderer applies to every subclass, present and
 * future, from one declaration. That is the property the di.xml `data` block was
 * reaching for and could not deliver.
 *
 * ===========================================================================
 * WHY beforeToHtml
 * ===========================================================================
 * It runs once per rendered line, after the renderer has been handed its item
 * and immediately before the template reads any of this. `getItem()` would have
 * worked too and is called several times per line, for no benefit.
 *
 * Each value is set only if absent, so a layout <argument> naming a different
 * implementation for one product type still wins.
 */
class CartItemViewModels
{
    /**
     * @var array<string, object>
     */
    private array $viewModels;

    public function __construct(
        CartQtyOptions $qtyOptions,
        CartItemPricing $pricing,
        MsrpHelper $msrpHelper
    ) {
        // Named exactly as the template reads them back, so the two lists can
        // be compared at a glance.
        $this->viewModels = [
            'qty_options_view_model' => $qtyOptions,
            'pricing_view_model' => $pricing,
            'msrp_helper' => $msrpHelper,
        ];
    }

    /**
     * @param Renderer $subject
     * @return void
     */
    public function beforeToHtml(Renderer $subject): void
    {
        foreach ($this->viewModels as $key => $viewModel) {
            if ($subject->getData($key) === null) {
                $subject->setData($key, $viewModel);
            }
        }
    }
}
