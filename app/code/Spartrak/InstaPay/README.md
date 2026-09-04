# Spartrak_InstaPay

A **manual** InstaPay payment method: the customer transfers the amount
themselves in their own banking app, uploads a screenshot of the receipt, and a
member of staff approves or rejects it from the order.

Built from Figma `586:7352` / `586:7420` (desktop) and `687:21691` (mobile).

## What it is not

It is **not** a gateway. Nothing in this module talks to a bank, InstaPay, or
any other service — no API call leaves the server, and there is no callback.
The only thing the storefront can capture is the customer's *account* of having
paid, which is why:

- the order is created in `new` — received, awaiting review — and never in
  `processing`;
- approving is a human decision, taken in the admin, that produces an **offline**
  invoice, and that decision is what moves the order on;
- nothing anywhere in this module claims the money arrived.

## Relationship to `Paymob_Payment`

Paymob is this store's gateway and will carry most card and wallet traffic —
possibly including InstaPay itself once it is configured. This module is the
manual fallback the design specifies. The two coexist as separate methods with
separate codes, and a merchant switches this one off in
**Stores → Configuration → Sales → Payment Methods** the day Paymob covers it.

Neither is hardcoded anywhere in the checkout — see `Spartrak_Payment`.

## The flow

1. The shopper picks InstaPay in the checkout and presses the CTA.
2. `method-renderer/instapay.js` redirects them to `/instapay/transfer`. **No
   order exists yet** — the quote survives all the way to step 4.
3. That page shows the merchant's transfer number and masked account name, and
   takes their phone number plus a JPG/JPEG/HEIC screenshot. A thumbnail of the
   chosen file is drawn from the browser's own copy of it
   (`web/js/proof-preview.js`), so they can see WHICH screenshot is attached
   before they commit — the mistake this screen invites is picking the wrong one
   out of a camera roll of near-identical ones, and a file name cannot catch it.
4. `Controller/Transfer/Save` validates the number and stores the receipt, THEN
   places the order, then records the transfer against it and comments its
   history. The order lands in `new`. Then the success page.
5. An admin opens the order, sees the panel, compares the receipt with the bank
   statement, and approves (offline invoice, and the order moves to `processing`)
   or rejects (order left open, comment added, so the customer can be contacted).
6. The customer's own order page shows the receipt back to them —
   `صورة التحويل`, behind their session. See below.

**Opening the transfer page costs nothing and leaving it costs nothing.** The
order used to be created at step 2, so pressing back cancelled one and the sales
grid filled with orders nobody placed. The basket stays the shopper's until they
actually submit; `Controller/Transfer/Save`'s header records the ordering
argument in full, including what happens if the basket changed while they were in
their banking app.

## Where the receipts live, and why

`var/spartrak/instapay/xx/yy/<random>.<ext>` — **outside the document root**.

A transfer receipt is a screenshot of somebody's banking app: an account, a
name, an amount. Anything under `pub/media` is served straight by the web server
with no session, no ACL and no logging, so a single guessed or leaked URL
exposes it permanently.

Admins reach them through `Controller/Adminhtml/Proof/View`, which is behind the
`Spartrak_InstaPay::proof` ACL resource and sends `nosniff`, a sandboxing CSP,
and `Cache-Control: private, no-store`.

The stored filename is generated, never the uploaded one, and the type is
decided from the file's own bytes rather than its name or its `Content-Type` —
both of which the browser lets anyone forge.

## Configuration

**Stores → Configuration → Sales → Payment Methods → InstaPay (manual transfer)**

| Field | Notes |
|---|---|
| Enabled | Off by default. A method that takes real money must not switch itself on at install. |
| Title | What the customer sees on the payment row. |
| Transfer number | **The number customers send money to.** Check it digit by digit; there is no automatic reconciliation to catch a mistake. |
| Name shown in InstaPay | The masked account name their app displays, e.g. `E**** F**** T**** A***`. Enter it already masked. |
| New order status | Leave as Pending Payment unless you have a reason not to. |

The method refuses to offer itself until the transfer number is filled in — see
`Model\Config::isUsable()`.

## Files worth reading first

| File | Why |
|---|---|
| `Model/ProofStorage.php` | The security argument, in full: where files go, why, and how the type is decided. |
| `Controller/Transfer/Save.php` | What is and is not claimed when a receipt arrives. |
| `Controller/Adminhtml/Proof/Review.php` | The one place an order becomes paid. |
| `etc/db_schema.xml` | Why the transfer is its own table and not columns on the order. |
| `etc/di.xml` | Why the method is an Adapter and has no command pool. |

## The receipt has two doors, and they are guarded separately

`var/spartrak/instapay` is outside the document root, so every view of a receipt
goes through a controller. There are now two, for two different readers:

| Route | Reader | Guard |
|---|---|---|
| `Controller/Adminhtml/Proof/View` | a member of staff | admin session + the `Spartrak_InstaPay::proof` ACL resource |
| `Controller/Proof/View` | the customer who uploaded it | customer session **and** `order.customer_id === session customer id` |

The second exists because Figma puts the receipt on the customer's own order
page (`573:21514`), where it answers a question that is theirs to ask: did the
right screenshot arrive? It is rendered by `ViewModel\OrderTransfer` +
`view/frontend/templates/order/receipt.phtml`, added to the order page as a
child block by this module's own `sales_order_view.xml`.

**It is NOT on `Block\Info`, and must not be.** That block is rendered into the
order confirmation email as well as the order page
(`Sales\Model\Order\Email\Sender\OrderSender::getPaymentHtml`), and a banking
screenshot has no business in an inbox or a forwarding rule. The order page is a
different surface with a different guarantee — a signed-in session and
`Cache-Control: no-store` — so it gets its own block rather than a relaxed
shared one.

`transfer_id` is a sequential integer in the URL, so the ownership comparison is
the whole security of the customer door: without it a signed-in shopper could
walk the range. Every failure returns the same bare 404 — a distinguishable 403
would say "this id exists and belongs to someone else", which is enough to
enumerate the store's transfer volume. The reason is logged at warning level, so
the response is opaque to the client and not to the operator.

## Two fixes worth knowing about

**Approving a transfer moves the order.** `Proof\Review::approve()` sets
`setIsInProcess(true)` after registering the invoice. Registering an invoice does
not change an order's state — Magento's own invoice controller takes that step
with the same line, and `ResourceModel\Order::save()` reads the flag through
`Handler\State::check()`. Without it, approving invoiced the order and left it in
`new` / `pending`: the admin's status field read "Pending" beside fully invoiced
items, and the storefront tracker stayed parked at `بانتظار الموافقة` for an
order somebody had just approved.

**The `section_data_clean` cookie carries the store code, not `'1'`.**
`customer-data.js` reads it as `!_.isEmpty($.cookieStorage.get('section_data_clean'))`,
and that getter JSON-parses the cookie: `"1"` becomes the number 1, and
underscore's `isEmpty` calls a number empty. So the guard read "empty", the
sections were never reloaded, and the minicart kept the items of an order that
had just been placed until the shopper opened the cart page. Magento's own writer
of this cookie (`Store\Controller\Store\SwitchAction\CookieManager`) stores a
store code for the same reason; both writers here now do the same.
