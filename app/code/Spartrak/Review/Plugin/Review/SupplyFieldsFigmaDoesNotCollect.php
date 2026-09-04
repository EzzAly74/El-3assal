<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\Review\Plugin\Review;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Review\Controller\Product\Post;
use Psr\Log\LoggerInterface;

/**
 * Fills `nickname` and `title` for a review form that does not ask for them.
 *
 * ===========================================================================
 * THE CONFLICT THIS RESOLVES
 * ===========================================================================
 * Figma's review dialog (1207:30485) collects exactly three things: a star
 * value, a comment, and a press of `اترك تعليقك`. Magento requires two more —
 * `Review\Model\Review::validate()` rejects a review with an empty `title`
 * ("Please enter a review summary") or an empty `nickname`, and both columns in
 * `review_detail` are NOT NULL.
 *
 * Adding the two fields to the dialog would be adding UI the design does not
 * have. Removing the requirement would mean forking core's validation. So the
 * fields are SUPPLIED, from facts the store already holds, before core reads
 * the request.
 *
 * ===========================================================================
 * WHERE EACH VALUE COMES FROM
 * ===========================================================================
 * `nickname` — the signed-in customer's FIRST NAME. This storefront
 *   authenticates by phone number and every account therefore has a real name
 *   on it, so there is nothing to ask: the shopper's own first name is a better
 *   nickname than one they would invent at the point of writing a review, and
 *   it is what the design implies by not asking.
 *
 *   A GUEST gets nothing from this plugin, on purpose. The dialog's CTA asks a
 *   guest to sign in first (see the template), because a review with no
 *   identity behind it is exactly the review this store does not want, and
 *   because inventing "Guest" as a nickname would put a fake name on a public
 *   page.
 *
 * `title` — the opening of the comment, trimmed to a whole word.
 *
 *   This is the one value with no natural source, and the alternatives were
 *   worse. A constant ("Review") would print the same word on every row of the
 *   admin's review grid, which is the column a moderator scans. Leaving it
 *   empty fails validation. Deriving it from the shopper's own first words
 *   invents nothing — every character is theirs — and it makes the grid
 *   readable, which is the only place `title` is used on this storefront: the
 *   Spartrak PDP panel prints no review titles because Figma draws none.
 *
 * ===========================================================================
 * WHY A `before` PLUGIN ON THE CONTROLLER
 * ===========================================================================
 * `Post::execute()` reads the whole post value in its first lines and hands it
 * to `setData()` before validating. A `before` plugin is therefore the last
 * point at which the request can still be completed — the same sequencing
 * argument, and the same shape, as
 * Spartrak\CustomerAddress\Plugin\FillCityOnAddressRepositorySave.
 *
 * It writes onto the REQUEST rather than onto the review model, because the
 * controller also stashes the post value in the session to redisplay after a
 * validation failure. Filling the model instead would leave the session copy
 * short of two fields and the second attempt would fail where the first
 * succeeded.
 *
 * IT NEVER OVERWRITES. A value already on the request wins — so an integration
 * or a future form that does collect a nickname is untouched by this class.
 */
class SupplyFieldsFigmaDoesNotCollect
{
    /**
     * Long enough to tell two reviews apart in the admin grid, short enough not
     * to be the whole comment repeated. Broken on a word boundary.
     */
    private const TITLE_MAX_LENGTH = 60;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customers,
        private readonly StringUtils $string,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Post $subject
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeExecute(Post $subject): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        $this->fill('nickname', fn (): string => $this->customerFirstName());
        $this->fill('title', fn (): string => $this->titleFromDetail());
    }

    /**
     * @param callable():string $resolve
     */
    private function fill(string $field, callable $resolve): void
    {
        $existing = trim((string) $this->request->getPostValue($field, ''));

        if ($existing !== '') {
            return;
        }

        $value = trim($resolve());

        if ($value !== '') {
            $this->request->setPostValue($field, $value);
        }
    }

    /**
     * The signed-in customer's first name, or '' for a guest.
     *
     * Falls back to the full name when a record somehow has no first name, and
     * to '' on any failure — at which point core's own validation reports
     * "Please enter a nickname" and the shopper is told something is wrong
     * rather than having a review silently attributed to nobody.
     */
    private function customerFirstName(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }

        try {
            $customer = $this->customers->getById((int) $this->customerSession->getCustomerId());
            $first = trim((string) $customer->getFirstname());

            return $first !== '' ? $first : trim((string) $customer->getLastname());
        } catch (\Exception $e) {
            $this->logger->warning('Spartrak Review: could not read the reviewer\'s name.', [
                'customer_id' => (int) $this->customerSession->getCustomerId(),
                'exception' => $e,
            ]);

            return '';
        }
    }

    /**
     * The comment's opening, cut on a word boundary.
     *
     * `StringUtils` rather than substr: the comment is Arabic, and a byte-wise
     * cut would split a multi-byte character and store a broken sequence.
     */
    private function titleFromDetail(): string
    {
        $detail = trim((string) $this->request->getPostValue('detail', ''));

        if ($detail === '') {
            // Nothing to derive from. Core will refuse the review for the
            // missing comment anyway, which is the message the shopper needs.
            return '';
        }

        // Collapse newlines first, so a title is never a fragment of line one
        // followed by half of line two.
        $detail = trim((string) preg_replace('/\s+/u', ' ', $detail));

        if ($this->string->strlen($detail) <= self::TITLE_MAX_LENGTH) {
            return $detail;
        }

        $cut = $this->string->substr($detail, 0, self::TITLE_MAX_LENGTH);
        $lastSpace = $this->string->strrpos($cut, ' ');

        // A single 60-character word (a part number, say) has no space to break
        // on; the hard cut is then the right answer.
        return $lastSpace > 0 ? trim($this->string->substr($cut, 0, $lastSpace)) : $cut;
    }
}
