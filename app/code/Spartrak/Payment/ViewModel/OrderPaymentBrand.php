<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Payment\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Spartrak\Payment\Model\PresentationCatalog;

/**
 * The 44px brand mark on the order page's `وسيلة الدفع` card — Figma 573:21506.
 *
 * ===========================================================================
 * ONE JOB, BECAUSE ONLY ONE THING WAS MISSING
 * ===========================================================================
 * A larger view model was written here first — title, description, mark — and
 * cut back, because measuring the page showed the other two were already
 * right. `.spartrak-order-panel__payment` in components/_account.less styles
 * the `<dl class="payment-method">` that every Magento payment info block
 * emits, and it styles it to Figma's own metrics: `dt` at 16/700 on
 * text/primary is 573:21508, `dd` at 14/400 on text/secondary is 573:21510. So
 * the card's title and its line of explanation come from the method's own info
 * block and are correct; the avatar beside them was the part with nothing
 * rendering it.
 *
 * Adding a second source for the title would also have put the method's name on
 * the page twice, and would have meant either duplicating or suppressing an
 * info block that is ALSO rendered into the order confirmation email
 * (Sales\Model\Order\Email\Sender\OrderSender::getPaymentHtml). Reading the one
 * that was already there is both smaller and safer.
 *
 * ===========================================================================
 * THE MARK IS ADMIN-MANAGED, LIKE THE CHECKOUT ROW'S
 * ===========================================================================
 * It comes from Model\PresentationCatalog — the same dynamic rows under
 * Stores > Configuration > Sales > Checkout that decide which marks the
 * checkout's payment row shows. So the order page and the funnel cannot
 * disagree about what InstaPay looks like, and enabling a new method with its
 * own logo stays a configuration change (CLAUDE.md section 7).
 *
 * Returns null rather than a placeholder when a method has no mark configured:
 * the template then draws no avatar at all, instead of an empty ring where a
 * logo should be.
 */
class OrderPaymentBrand implements ArgumentInterface
{
    /**
     * @var array<string, array{description: string, brands: array<int, array{url: string, label: string}>}>|null
     */
    private ?array $presentation = null;

    public function __construct(
        private readonly PresentationCatalog $catalog
    ) {
    }

    /**
     * The first configured mark for this order's payment method.
     *
     * FIRST, not all of them. The checkout's row shows a strip — a card row
     * legitimately carries Visa, Mastercard and Meeza together — but 573:21504
     * draws a single circular avatar, so a method with several marks shows the
     * one the merchant put first.
     *
     * @return array{url: string, label: string}|null
     */
    public function get(?OrderInterface $order): ?array
    {
        $code = (string) ($order?->getPayment()?->getMethod() ?? '');

        if ($code === '') {
            return null;
        }

        // Read once per request. One json_decode over a config-cached value,
        // but there is no reason to do it per call either.
        if ($this->presentation === null) {
            $this->presentation = $this->catalog->getForCurrentStore();
        }

        $brands = $this->presentation[$code]['brands'] ?? [];

        return $brands === [] ? null : $brands[0];
    }
}
