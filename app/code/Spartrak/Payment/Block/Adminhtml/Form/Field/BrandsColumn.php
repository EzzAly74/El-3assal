<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use Spartrak\Payment\Model\BrandMarks;

/**
 * The "brand marks" cell - a multiselect over the marks this store ships.
 *
 * ===========================================================================
 * WHY IT IS A MULTISELECT AND NOT A COMMA-SEPARATED TEXT BOX
 * ===========================================================================
 * A text box would have been three lines instead of forty. It would also have
 * asked a merchant to type `vodafone_cash` exactly, with no list to check
 * against and no feedback when they got it wrong - the row would just render
 * with a mark missing. The keys are an implementation detail; the merchant
 * should be choosing "Vodafone Cash".
 *
 * ===========================================================================
 * THE `multiple` ATTRIBUTE AND THE `[]` ON THE NAME
 * ===========================================================================
 * Html\Select renders a single-choice <select> unless both are present: the
 * attribute makes the control multi-choice, and PHP only collects repeated
 * values into an array when the field name ends in `[]`. Setting one without
 * the other yields a control that looks multi-choice and submits exactly one
 * value - which is why both happen in the same method here rather than in two
 * places that can drift.
 */
class BrandsColumn extends Select
{
    public function __construct(
        Context $context,
        private readonly BrandMarks $brandMarks,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function setInputName(string $value): self
    {
        return $this->setName($value . '[]');
    }

    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $options = [];

            foreach ($this->brandMarks->getOptionLabels() as $value => $label) {
                $options[] = ['value' => $value, 'label' => $label];
            }

            $this->setOptions($options);
        }

        $this->setClass('spartrak-payment-brands-select admin__control-multiselect');
        // size=4 so several marks are visible at once without the row growing
        // to the full height of the catalogue.
        $this->setExtraParams('multiple="multiple" size="4"');

        return parent::_toHtml();
    }
}
