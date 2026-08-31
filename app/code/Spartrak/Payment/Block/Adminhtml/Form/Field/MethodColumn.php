<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use Spartrak\Payment\Model\Config\Source\ActiveMethods;

/**
 * The "payment method" cell of the presentation rows field.
 *
 * A picker rather than a free-text code box. The row is keyed on the method
 * code, and a typo in a code produces a row that silently never matches - the
 * checkout would look untouched and the merchant would have no way to tell why.
 */
class MethodColumn extends Select
{
    public function __construct(
        Context $context,
        private readonly ActiveMethods $methods,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * AbstractFieldArray hands the cell its name and id through these two
     * setters; Html\Select reads `name` and `id`. Bridging them is the whole
     * job of a column renderer.
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->methods->toOptionArray());
        }

        $this->setClass('spartrak-payment-method-select admin__control-select');

        return parent::_toHtml();
    }
}
