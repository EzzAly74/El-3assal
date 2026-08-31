# Spartrak_Payment

Dresses payment methods for the Spartrak checkout row (Figma `551:11313`).

## What it does not do

**It does not decide which payment methods exist.** That is Magento's answer and
only Magento's — the checkout renders whatever `paymentMethods()` gives it, in
whatever order **Stores → Configuration → Sales → Payment Methods** puts them.

Figma draws four rows. The store might have one, or seven, or a Paymob gateway
plus two offline methods. All of those have to work, so nothing here contains a
list of payment methods, and nothing here filters or re-orders them.

## The problem it solves

Figma's payment row has a title, a description and a cluster of brand logos.
Magento gives a payment method a title. The other two have to come from
somewhere, and that somewhere must be editable — a merchant enabling a new
method should not need a developer.

So:

| Thing | Owner |
|---|---|
| Which methods exist and their order | Magento payment configuration |
| A method's title | Magento payment configuration |
| Its description line and brand marks | **this module**, admin-managed |
| The row's border, padding, type ramp | the theme (`_checkout.less`) |

A method with **no** configuration here still renders — under its own Magento
title, with no marks. That fallback is the important part: it is what makes
enabling a method a configuration change rather than a code change.

## Configuration

**Stores → Configuration → Sales → Checkout → Spartrak Payment Rows**

One row per method you want to dress: pick the method, write the description,
choose the marks. There is deliberately **no sort order column** — Magento
already orders payment methods, and a second ordering that could disagree with
the first would be a bug with no way to tell which had won.

## How every method ends up in the same row

`Magento_Checkout/js/view/payment/default` is the component every payment
renderer extends — verified for `Paymob_Payment`'s renderer and for core's
offline methods. A single mixin on it assigns the Figma row template after
`_super()` runs, which is what beats a subclass's own `defaults.template`.

A method that genuinely needs its own layout (a card form drawn inline, a
saved-cards picker) sets `spartrakKeepTemplate: true` in its jsLayout config and
keeps whatever template it declared.

## The brand marks

`view/frontend/web/images/payment/` — third-party trademarks, exported from
Figma at their original colours, rendered through `<img>`.

**Never re-tint them and never render them through a CSS mask.** A mask reads
only the alpha channel and would flatten Visa, Meeza and Vodafone Cash to a
single colour, which is both wrong and a misuse of someone else's mark.

The rasters were downscaled to 88px on the shorter edge — twice the 44px tile
they render in — so they stay sharp at 2× DPR without shipping the 1400px
originals Figma exports. All ten together are about 64 KB.

Adding a brand is one file plus one line in `Model/BrandMarks.php`. The *set* is
code because a mark has to be the real artwork from the brand owner; the
*mapping* is configuration because that is what a merchant changes.

## Relationship to `Paymob_Payment`

Paymob registers one gateway method (`paymob_payment`) and ships its own
Knockout template with an inline `<style>` block. The mixin re-templates it like
any other method, so it gets the Figma row and its description and logo become
admin configuration. Paymob's own component — its `placeOrder`, its redirect to
the hosted page — is untouched.

## Files worth reading first

| File | Why |
|---|---|
| `Model/PresentationCatalog.php` | The boundary between "which methods exist" and "how a row looks", and why this is config rather than an entity. |
| `view/frontend/web/js/view/payment/method-row-mixin.js` | Why the mixin is on the base renderer, and why it assigns in `initialize()`. |
| `Model/BrandMarks.php` | Why the marks live here, and why they are never masked. |
