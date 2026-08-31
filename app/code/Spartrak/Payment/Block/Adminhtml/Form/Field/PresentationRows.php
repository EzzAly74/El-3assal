<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

/**
 * Stores > Configuration > Sales > Checkout > Spartrak payment rows.
 *
 * One row per payment method the merchant wants to dress up. A method with no
 * row still appears in the checkout under its own Magento title - see
 * Spartrak\Payment\Model\PresentationCatalog for why that fallback matters.
 */
class PresentationRows extends AbstractFieldArray
{
    private ?MethodColumn $methodRenderer = null;
    private ?BrandsColumn $brandsRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('method', [
            'label'    => __('Payment method'),
            'renderer' => $this->getMethodRenderer(),
        ]);
        $this->addColumn('description', [
            'label' => __('Description'),
            'class' => 'input-text',
            'size'  => 40,
        ]);
        $this->addColumn('brands', [
            'label'    => __('Brand marks'),
            'renderer' => $this->getBrandsRenderer(),
        ]);
        /**
         * THERE IS DELIBERATELY NO SORT ORDER COLUMN.
         *
         * Magento already orders payment methods, from
         * payment/<code>/sort_order, and the checkout renders them in that
         * order. A second ordering here would be a duplicate of that logic
         * that a merchant could set to disagree with it - and when the two
         * disagreed, nothing would tell them which one won.
         *
         * Row order in THIS grid is meaningless; it is a lookup table.
         */
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add payment row');
    }

    /**
     * Restore the multiselect's saved value.
     *
     * The admin template re-populates each cell by handing its stored value to
     * prototype's `setValue`. For a single <select> a string is right. For a
     * multiple <select> prototype only marks several options when it is handed
     * an ARRAY - given the comma-joined string the config table holds, it looks
     * for one option literally named "visa,meeza", finds none, and the row
     * silently reopens with nothing selected.
     *
     * Splitting it here is the smallest fix, and it is in the right place: the
     * value on disk stays a plain string that every other reader can use.
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $values = $row->getData('column_values');

        if (!is_array($values)) {
            return;
        }

        $brandsId = $this->_getCellInputElementId((string) $row->getData('_id'), 'brands');
        $stored = $row->getData('brands');

        if (is_string($stored)) {
            $values[$brandsId] = $stored === '' ? [] : explode(',', $stored);
        } elseif (is_array($stored)) {
            $values[$brandsId] = $stored;
        }

        $row->setData('column_values', $values);
    }

    private function getMethodRenderer(): MethodColumn
    {
        if ($this->methodRenderer === null) {
            $this->methodRenderer = $this->getLayout()->createBlock(
                MethodColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->methodRenderer;
    }

    private function getBrandsRenderer(): BrandsColumn
    {
        if ($this->brandsRenderer === null) {
            $this->brandsRenderer = $this->getLayout()->createBlock(
                BrandsColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->brandsRenderer;
    }
}
