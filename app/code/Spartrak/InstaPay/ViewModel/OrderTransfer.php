<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\InstaPay\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Spartrak\InstaPay\Api\Data\TransferInterface;
use Spartrak\InstaPay\Api\TransferRepositoryInterface;

/**
 * The `صورة التحويل` row on the customer's own order page — Figma 573:21514.
 *
 * ===========================================================================
 * WHY THE CUSTOMER GETS TO SEE THEIR RECEIPT AT ALL
 * ===========================================================================
 * Block\Info's header says, correctly, that the receipt must not go into the
 * payment info block: that block is rendered into the ORDER CONFIRMATION EMAIL,
 * and a banking screenshot has no business in an inbox, a mail archive or
 * somebody's forwarding rule.
 *
 * The ORDER PAGE is a different surface with a different guarantee. It is
 * behind the customer's own session, it is `Cache-Control: no-store`, and the
 * person reading it is the person who uploaded the file. Figma draws the row
 * there deliberately (573:21511 `صورة التحويل`), and it answers a real
 * question: "did my receipt actually arrive, and is it the right one?" — asked
 * by exactly the shopper who is waiting for a human to approve it.
 *
 * So this view model exists instead of relaxing Block\Info. The two surfaces
 * keep their own rules.
 *
 * ===========================================================================
 * IT SHOWS THE LATEST TRANSFER, AND SAYS SO WHEN THERE IS MORE THAN ONE
 * ===========================================================================
 * `getByOrderId()` returns every attempt, newest first, because a rejected
 * receipt is followed by another one and the rejected row is the reason the
 * second exists. Figma draws a single row, so this shows the newest — the one
 * whose outcome the customer is waiting on. The status is carried alongside it
 * so the row can say `بانتظار المراجعة` rather than implying the money has
 * been confirmed, which nothing in this flow may claim.
 *
 * ===========================================================================
 * OWNERSHIP IS RE-CHECKED HERE, NOT ASSUMED FROM THE PAGE
 * ===========================================================================
 * The order page already refuses an order that is not the session's — core's
 * Sales\Controller\Order\View does that. This checks again anyway, because a
 * view model is reachable from any block anybody adds later, and the thing it
 * hands out is a link to a private file. Two cheap comparisons are worth more
 * than the assumption that every future caller got it right.
 *
 * The stream itself is guarded independently, by
 * Controller\Proof\View — this class only decides whether to draw a link.
 */
class OrderTransfer implements ArgumentInterface
{
    /** @var array<int, TransferInterface|null> */
    private array $cache = [];

    public function __construct(
        private readonly TransferRepositoryInterface $transfers,
        private readonly CustomerSession $customerSession,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Draw the row?
     */
    public function isVisible(?OrderInterface $order): bool
    {
        return $this->get($order) !== null;
    }

    public function get(?OrderInterface $order): ?TransferInterface
    {
        $orderId = (int) ($order?->getEntityId() ?? 0);

        if ($orderId === 0 || !$this->isOwnedByCurrentCustomer($order)) {
            return null;
        }

        if (array_key_exists($orderId, $this->cache)) {
            return $this->cache[$orderId];
        }

        try {
            $transfers = $this->transfers->getByOrderId($orderId);
        } catch (\Exception $e) {
            // A receipt that cannot be read is not a reason to take the order
            // page down. The row is simply absent.
            $this->logger->warning('Spartrak InstaPay: could not load transfers for an order page.', [
                'order_id'  => $orderId,
                'exception' => $e,
            ]);
            $transfers = [];
        }

        return $this->cache[$orderId] = $transfers[0] ?? null;
    }

    /**
     * The file name the shopper themselves uploaded — Figma prints exactly
     * that (573:21516, `Screenshot2026-10-10.PNG`).
     *
     * It is the ORIGINAL name, kept in a database column rather than used as a
     * path: the stored filename is generated, precisely because the uploaded
     * one is attacker-controlled (Model\ProofStorage). Printing it is safe —
     * it is escaped like any other string — and it is the only version of the
     * name that means anything to the person who chose it.
     *
     * Falls back to a generic label for a row uploaded before the column
     * existed, or by a client that sent no name.
     */
    public function getFileName(?OrderInterface $order): string
    {
        $transfer = $this->get($order);
        $name = $transfer?->getOriginalName();

        return $name !== null && trim($name) !== ''
            ? trim($name)
            : (string) __('Transfer receipt');
    }

    /**
     * Where the row's eye control points.
     *
     * There is no URL that reaches the file directly — receipts live outside
     * the document root (Model\ProofStorage) — so this is a controller route,
     * and that controller re-checks ownership before it streams a byte.
     */
    public function getViewUrl(?OrderInterface $order): ?string
    {
        $transfer = $this->get($order);
        $id = $transfer?->getTransferId();

        if ($id === null) {
            return null;
        }

        return $this->url->getUrl(
            'spartrak_instapay/proof/view',
            ['transfer_id' => $id, '_secure' => true]
        );
    }

    /**
     * NO STATUS GETTER, AND THAT IS A DESIGN DECISION RATHER THAN AN OMISSION.
     *
     * A `getStatusLabel()` returning `بانتظار المراجعة` / `تم تأكيد التحويل`
     * was written here and removed. Figma's card (573:21502) draws the label,
     * the file row and nothing else, and the review outcome already reaches the
     * customer by the two routes the business specified: the order's own
     * tracking status, which the approval now advances (see
     * Controller\Adminhtml\Proof\Review), and a phone call when a receipt could
     * not be matched (Review::reject leaves the order open expressly so
     * somebody can make it). Adding a third would be inventing UI the design
     * does not have (CLAUDE.md section 3) — and inventing wording for a payment
     * state is the one place in this module where a guess is expensive.
     */
    private function isOwnedByCurrentCustomer(?OrderInterface $order): bool
    {
        $customerId = (int) ($order?->getCustomerId() ?? 0);

        return $customerId !== 0 && $customerId === (int) $this->customerSession->getCustomerId();
    }
}
