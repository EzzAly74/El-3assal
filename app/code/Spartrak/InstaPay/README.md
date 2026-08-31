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

- the order is created in `pending_payment`, not `processing`;
- approving is a human decision, taken in the admin, that produces an **offline**
  invoice;
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
2. Magento creates the order in `pending_payment`. The cart is converted and
   stock is reserved, so nobody else can buy the last one while they are in
   their banking app.
3. `method-renderer/instapay.js` redirects them to `/instapay/transfer`.
4. That page shows the merchant's transfer number and masked account name, and
   takes their phone number plus a JPG/JPEG/HEIC screenshot.
5. `Controller/Transfer/Save` stores the receipt, records the transfer, moves the
   order to `new`, and adds a comment to its history. Then the success page.
6. An admin opens the order, sees the panel, compares the receipt with the bank
   statement, and approves (invoice, offline capture) or rejects (order left
   open, comment added, so the customer can be contacted).

An abandoned transfer leaves a `pending_payment` order — exactly the state that
exists to be chased or cancelled.

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
