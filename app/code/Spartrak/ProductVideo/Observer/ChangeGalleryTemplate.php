<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\ProductVideo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Points the product form's media gallery at this module's copy of the item
 * template.
 *
 * One line, and it is a deliberate mirror of
 * Magento\ProductVideo\Observer\ChangeTemplateObserver — which does exactly
 * this to swap Magento_Catalog's template for its own. Two modules, one event,
 * and the later one wins; `etc/module.xml` sequences this module after
 * Magento_ProductVideo so "later" is not left to chance.
 *
 * The template it selects is Magento_ProductVideo's, plus nine hidden inputs.
 * See view/adminhtml/templates/helper/gallery.phtml for why that fork is
 * necessary and how to re-merge it.
 */
class ChangeGalleryTemplate implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $block = $observer->getBlock();

        if ($block === null) {
            return;
        }

        $block->setTemplate('Spartrak_ProductVideo::helper/gallery.phtml');
    }
}
