<?php
/**
 * Paymob Payment Adminhtml Block
 *
 * Compatible with both Magento 2.3.x and 2.4.x+
 *
 * @category  Paymob
 * @package   Paymob_Payment
 * @author    Aml Fares
 * @license   Open Software License ("OSL") v. 3.0
 */

namespace Paymob\Payment\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\App\ObjectManager;

class Payment extends Fieldset
{
    /** @var \Magento\Framework\View\Helper\SecureHtmlRenderer|null */
    private $secureRenderer = null;

    public function __construct(
        \Magento\Backend\Block\Context $context,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\View\Helper\Js $jsHelper,
        array $data = [],
        $secureRenderer = null
    ) {
        // ✅ Only use SecureHtmlRenderer if Magento >= 2.4 and class exists
        if (class_exists(\Magento\Framework\View\Helper\SecureHtmlRenderer::class)) {
            $this->secureRenderer = $secureRenderer
                ?? ObjectManager::getInstance()->get(\Magento\Framework\View\Helper\SecureHtmlRenderer::class);

            parent::__construct($context, $authSession, $jsHelper, $data, $this->secureRenderer);
        } else {
            // Magento 2.3 fallback
            parent::__construct($context, $authSession, $jsHelper, $data);
        }
    }

    protected function _getFrontendClass($element)
    {
        return parent::_getFrontendClass($element) . ' paymob-with-button';
    }

    protected function _getHeaderTitleHtml($element)
    {
        $htmlId = $element->getHtmlId();
        $groupConfig = $element->getGroup();

        $html = '<div class="config-heading">';
        $html .= '<div class="paymob-button-container" style="text-align: right;">';
        $html .= '<button type="button" class="button paymob-action-configure" id="' . $htmlId . '-head">';

        $html .= '<span class="state-closed">' . __('Configure') . '</span>';
        $html .= '<span class="state-opened" style="display:none;">' . __('Close') . '</span>';
        $html .= '</button>';

        // ✅ Attach onclick using SecureHtmlRenderer if available
        $onClickJs = "paymobToggleSection.call(this, '" . $htmlId . "', '" . $this->getUrl('adminhtml/*/state') . "');event.preventDefault();";

        if ($this->secureRenderer) {
            $html .= $this->secureRenderer->renderEventListenerAsTag(
                'onclick',
                $onClickJs,
                'button#' . $htmlId . '-head'
            );
        } else {
            $html .= "<script>
                require(['jquery'], function($){
                    $('#{$htmlId}-head').on('click', function(e){ {$onClickJs} });
                });
            </script>";
        }

        if (!empty($groupConfig['more_url'])) {
            $html .= '<a class="link-more" href="' . $groupConfig['more_url'] . '" target="_blank">' . __('Learn More') . '</a>';
        }

        $html .= '</div>';
        $html .= '<div class="heading" style="text-align: center;">';

        try {
            $logoUrl = $this->getViewFileUrl('Paymob_Payment::images/paymob.png');
            $html .= '<img src="' . $logoUrl . '" alt="Paymob" style="height:48px;display:block;margin:0 auto 8px auto;" />';
        } catch (\Exception $e) {}

        $html .= '<strong style="display:block;">' . $element->getLegend() . '</strong>';
        $html .= '<div class="config-alt" style="margin-top:4px;font-weight:normal;font-size:0.9em;color:#666;">'
            . ($element->getComment() ?: '') . '</div>';
        $html .= '</div></div>';

        return $html;
    }

    protected function _getHeaderCommentHtml($element)
    {
        return '';
    }

    protected function _isCollapseState($element)
    {
        return true;
    }

    protected function _getExtraJs($element)
    {
        $htmlId = $element->getHtmlId();

        $script = <<<JS
        require(['jquery', 'prototype'], function(jQuery) {
            window.paymobToggleSection = function(id, url) {
                var button = jQuery('#' + id + '-head');
                button.toggleClass('open');
                var opened = button.hasClass('open');
                button.find('.state-closed').toggle(!opened);
                button.find('.state-opened').toggle(opened);
                Fieldset.toggleCollapse(id, url);
            };

            jQuery(document).ready(function() {
                var button = jQuery('#{$htmlId}-head');
                button.removeClass('open');
                button.find('.state-closed').show();
                button.find('.state-opened').hide();
                Fieldset.toggleCollapse('{$htmlId}', '');
            });
        });
        JS;

        return $this->_jsHelper->getScript($script);
    }

    protected function _isPaymentEnabled($element)
    {
        $configPath = $element->getGroup()['id'] . '/active';
        return $this->_scopeConfig->isSetFlag(
            'payment/' . $configPath,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
