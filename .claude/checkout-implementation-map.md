# Checkout + Cart — Figma implementation map

Produced by inspecting **26 frames** in file `6FRlQfPIncVUvNiJLn2kbT` via the
Figma remote MCP on 2026-08-30, extended on 2026-08-31. Every dimension, colour
and node id below was read off a node. Nothing here is inferred.

**Status legend:** `BUILT` — implemented and statically validated ·
`BUILT (core)` — Magento's own component, re-templated/restyled ·
`GAP` — not implemented, with the reason stated.

> **Nothing in this file has been verified against a running Magento.** There is
> no instance in this environment. Every "BUILT" means the code exists and
> passes static validation (PHP lint, class-load, XSD, Knockout parse, LESS
> compile, token resolution, reference resolution) — not that it was rendered.
> No pixel comparison against Figma has been performed.

---

## A. CHECKOUT — DESKTOP (12 states)

Page skeleton, identical in all states (frame 1440 wide):

```
Frame 2147235936   header — top banner 44 + navbar 157.42  (NO mega-menu row)
Main Container     y=199
  Progress bar     1440 x 97
  Main Container   two columns
    Order summary section   x=0    w=624    (renders LEFT on the LTR canvas,
                                             i.e. the reading END in RTL)
    Container / Payment section  x=624  w=816
Footer Links       1440 x 419
```

| # | Node | State | Status |
|---|---|---|---|
| 1 | `552:11748` | Shipping — address book EMPTY | **BUILT** — van 176², CTA, disabled continue (`#8faef0`) |
| 2 | `549:2753` | Shipping — addresses populated | **BUILT** — cards, default badge, radio selection, edit button |
| 3 | `551:10038` | Payment — nothing selected | **BUILT** — rows from live Magento methods, notes textarea |
| 4 | `554:10780` | Payment — method selected + promo | **BUILT** — checked radio 721:30533, promo panel relocated to the summary column |
| 5 | `554:11651` | Payment — promo APPLIED | **BUILT** — green apply button, green message, CTA amount refreshes |
| 6 | `554:11231` | Payment — PROCESSING | **BUILT (core)** — Magento's full-screen loader restyled into the card |
| 7 | `554:12084` | **Order success** | **BUILT** — items, payment title, ship-to/pickup, continue |
| 8 | `554:13119` | Shipping — **branch pickup** | **BUILT** (earlier phase) |
| 9 | `554:13750` | Shipping — **depot pickup** | **BUILT** (earlier phase) |
| 10 | `557:4731` | **Add-address modal** | **BUILT (core)** — Magento's form popup, re-labelled + restyled; fields reshaped by a LayoutProcessor plugin |
| 11 | `572:15198` | Payment — InstaPay selected | **BUILT** — no special case; it is one more dynamic row |
| 12 | `586:7352` | **InstaPay transfer page** | **BUILT** — `Spartrak_InstaPay`, full flow |

### Progress bar — `817:22383`
**BUILT** (Phase 1, unchanged). The newly inspected states confirm it.

### Order summary column (x=0, w=624) — **BUILT**
Card 528 wide, outer padding 8, inner padding 24, 28px rhythm (the inner frame's
664px height is exactly `24 + 26 + 28 + 486 + 28 + 48 + 24`).
Items 464×124 with an 18² qty badge straddling the image corner; totals block
`551:11170`; CTA 464×48.

### Payment method row — `554:10948` 720×86 — **BUILT**
Radio at the reading start, marks at the reading end (measured: radio x=680,
marks x=20 on a 720 row). Title/description/marks come from
`Spartrak_Payment`'s admin-managed presentation registry, **not** from markup —
see §F.

### Promo code panel — `554:11160` / `554:11806` — **BUILT (core)**
`Magento_SalesRule/js/view/payment/discount`, template-only override, relocated
from the payment step to the summary column.

### Notes field — `552:11505` — **BUILT**
Writes `quote.customer_note`, which Magento already carries to the order.

---

## B. CHECKOUT — MOBILE (12 frames) — 440 wide

| # | Node | State | Status |
|---|---|---|---|
| 1 | `669:13034` | Cart | **BUILT** (earlier phase) |
| 2 | `675:17510` | Cart empty | **BUILT** (earlier phase) |
| 3 | `675:21226` | Shipping — address book empty | **BUILT** |
| 4 | `687:15189` | Add-address BOTTOM SHEET | **BUILT (core)** — Magento's `responsive: true` modal, restyled as a sheet |
| 5 | `687:16474` | Payment | **BUILT** |
| 6 | `687:17805` | Payment + promo (default) | **BUILT** — stacked; the apply button is `action/inverse`, the SAME ink as desktop |
| 7 | `687:18104` | Payment + promo applied | **BUILT** |
| 8 | `687:18468` | Payment processing | **BUILT** |
| 9 | `687:18900` | Order success | **BUILT** |
| 10 | `687:20856` | Shipping — branch pickup | **BUILT** (earlier phase) |
| 11 | `687:21319` | Shipping — depot pickup | **BUILT** (earlier phase) |
| 12 | `687:21691` | InstaPay transfer | **BUILT** |

### Desktop → mobile differences (observed, not inferred)
- **Single column.** Summary moves BELOW the step content.
- **The summary drops its line items** on mobile (`687:16474` goes straight from
  the title to the subtotal). Implemented as `display: none`, not a template fork.
- **The checkout CTA is INLINE, not sticky.** Worth stating because the mobile
  CART (`669:13034`) *does* pin one — it draws two CTAs where the checkout draws
  one.
- Shipping-method cards stack; progress bar compact; `الرجوع` on its own row.
- Add-address becomes a bottom sheet.
- Segmented labels shorten.

---

## C. CART — DESKTOP + MOBILE — **BUILT** (earlier phase, audited 2026-08-31)

`553:4663` and `817:22551` are **both desktop cart frames**, 1440×1524, and are
near-identical. The mobile cart is `669:13034`. Navbar here is **223.42** tall —
the cart KEEPS the mega-menu row that checkout drops.

Audited against the frames this pass; two gaps found and closed:

| Gap | Fix |
|---|---|
| The ship-to row had no pin and no chevron | Pseudo-elements on core's collapsible title; the pin reuses `account-addresses.svg` (verified as the same glyph by scale-normalised path comparison, score 1.000) |
| The savings row was unstyled | Theme override of `Magento_SalesRule/cart/totals/discount.html` adds a `totals-discount` class — core gives every totals row the same class, so there was nothing else to hook on |

**Correction to a previous entry.** §E note 7 recorded the cart as having no
savings row, from the desktop frame alone. The **mobile cart shows one** — `وفرت`
with the info icon and a green amount, between the subtotal and the shipping
charge. It is now rendered on the cart, and it is also Magento's default: the
row hides itself when there is no discount.

Everything else verified present: header + `مسح الكل`, product cards, desktop qty
dropdown, mobile `− 1 +` stepper, remove, empty state, summary card, totals,
checkout CTA, sticky mobile CTA, instalments panel.

---

## D. SHARED COMPONENTS + ASSETS

### Assets exported this pass
`savings-info.svg` · `plus-16.svg` · `phone-16.svg` · `phone-call-20.svg` ·
`payment-method.svg` · `check-12.svg` · `upload-image.svg` ·
`illustrations/checkout-no-address.svg` (176²) ·
`illustrations/checkout-success.svg` (156²) ·
`Spartrak_InstaPay::images/instapay-brand.png` (100²) ·
10 payment brand marks in `Spartrak_Payment::images/payment/`

### Reused after verification — NOT re-exported
Confirmed as the same glyph by scale-normalised path comparison (score 1.000):
`pen-edit.svg` · `close.svg` · `chevron-down-16.svg` · `promo-discount.svg` ·
`delivery-truck.svg` (= the success page's ship-to icon) ·
`account-addresses.svg` (= the cart's ship-to pin)

`plus.svg` was **not** reused: it is the same glyph at a tighter crop
(0.625→12.29 of a 12.92 box, versus 3.33→12.67 of a 16 box), which renders
visibly differently at 16px. Exported as `plus-16.svg`.

### Payment brand marks — performance
Downscaled to 88px on the shorter edge (2× the 44px tile). 226 KB → 64 KB for
all ten. Rendered through `<img>`, never a CSS mask: a mask reads only the alpha
channel and would flatten third-party trademarks to a single tint.

### Design tokens added this pass
| Token | Value | Why |
|---|---|---|
| `--color-action-inverse` | `--primitive-ink` | The promo panel's dark apply button (`action/inverse`) — deliberately not navy |
| `--color-action-inverse-text` | `--primitive-surface` | its label |
| `--color-bg-brand-subtle` | `--primitive-blue-0` | the `الافتراضي` badge (Figma names it `blue/0`, a primitive name for a role) |

---

## E. RESOLVED AMBIGUITIES

1. **COD** — resolved by architecture, not by choice. The checkout renders
   whatever payment methods Magento reports; no method is named in any template.
2. **Mobile vs desktop depot rows** — implemented per viewport, as instructed.
3. **`رقم اضافي`** — a real `additional_phone` customer-address attribute
   (`Spartrak_CustomerAddress`), never `fax`.
4. **No mobile frame for "shipping with addresses populated"** — derived from the
   desktop card plus the mobile patterns in the other eleven frames.
5. **InstaPay** — built in full as `Spartrak_InstaPay`.
6. **Promo button colour** — the earlier note recorded desktop navy / mobile ink.
   Re-inspected: **both are `action/inverse` ink**. `554:11160` and `687:17805`
   carry the same variable.
7. **Savings row on the cart** — see §C. It is shown.
8. **Two cart frames** — one shared structure, as instructed.
9. **Cart qty control** — dropdown desktop, stepper mobile, as drawn.
10. **Summary card title** — desktop says `تفاصيل الطلب`, mobile says
    `ملخص الطلب`. Treated as drift; one string is used (`Order summary`), matching
    the mobile frame and the cart.

---

## F. WHAT IS ADMIN-MANAGED, NOT HARDCODED

| Thing | Where a merchant sets it |
|---|---|
| Which payment methods exist, and their order | Stores → Configuration → Sales → Payment Methods |
| A payment row's description and brand marks | Stores → Configuration → Sales → Checkout → **Spartrak Payment Rows** |
| InstaPay's transfer number and masked name | Stores → Configuration → Sales → Payment Methods → InstaPay |
| Branches, depots, operators | Stores → **Pickup Locations** |
| Shipping carriers, prices, delivery windows | Stores → Configuration → Sales → Delivery Methods |
| Instalments promo copy and artwork | Stores → Configuration → Sales → Checkout |

**Figma's four payment rows do not appear on their own.** The presentation
registry ships empty on purpose — seeding it would hardcode a method list. Until
a merchant adds rows, each method renders under its own Magento title with no
description and no marks, which is correct rather than broken.

---

## G. THE SAVED-ADDRESS EDIT FLOW

Both items previously listed here as gaps are implemented.

### Editing writes back to the same address — **BUILT**

`تعديل` now edits the address it belongs to. The card hands its id to the
shipping component, which prefills the form and remembers the id; the save goes
to `Spartrak\Checkout\Controller\Address\Save`, which loads that address,
checks it belongs to the signed-in customer, and writes back through
`AddressRepositoryInterface`.

The controller mirrors `Magento\Customer\Controller\Address\FormPost` -
Magento's own implementation of this operation - because it is subtle in three
ways that are invisible from the outside:

| Step | Why it matters |
|---|---|
| Extract through the `customer_address_edit` **metadata form** | Applies each attribute's own validation and picks up custom attributes, which is how `additional_phone` is handled without a line naming it |
| Resolve `region_id` into a full **RegionInterface** | An address saved with only a region id has no region NAME and prints with a blank governorate line |
| Merge extracted values **on top of** the existing address | The checkout form does not post company or postcode (hidden by the layout plugin); populating from the extracted values alone would blank them on every edit |

The customer id is read from the session and never from the request; an incoming
`address_id` is loaded and then compared against it, and a mismatch is reported
as "not found" so the endpoint cannot be used to probe which ids exist.

### `العنوان الافتراضي` → `default_shipping` — **BUILT**

The toggle writes the real Magento flag. It is **not** mapped to
`save_in_address_book`, which is left untouched at core's default.

> **The one trap here, recorded because it fails silently.**
> `Magento\Customer\Model\ResourceModel\Address\Relation` clears a customer's
> default with a **strict** `getIsDefaultShipping() === false`. Passing `0`, `'0'`
> or `''` turns the toggle off on screen and leaves the customer's default
> pointing at that address forever, with nothing to indicate it failed. The
> controller casts to a real `bool` before the value goes near the model.

`default_billing` is deliberately carried through unchanged rather than omitted:
the same Relation clears a default when it sees a strict `false`, so
re-asserting the address's own current state is what guarantees an edit cannot
drop the customer's default billing address.

After a save the server rebuilds the **whole** address list through Magento's own
`CustomerAddressDataProvider` and the browser replaces its list with it. Patching
just the saved address would leave two cards wearing the `الافتراضي` badge, because
making one address the default un-defaults another - `default_shipping` is a
single column on the CUSTOMER, not a flag on each address.

### Both viewports, one implementation

The desktop modal (`557:5173`) and the mobile bottom sheet (`687:15189`) are the
same Magento modal at two widths - `responsive: true` is what turns one into the
other. One component, one form, one save handler; nothing in the code knows
which viewport it is on.

---

## H. REMAINING GAPS

| # | Gap | Why |
|---|---|---|
| 1 | **Nothing has been run.** No `setup:upgrade`, no `di:compile`, no page render, no Lighthouse, no pixel comparison. | No Magento instance in this environment. Static validation only — see the top of this file for what that does and does not cover. |
