# Checkout + Cart — Figma implementation map

Produced by inspecting **26 frames** in file `6FRlQfPIncVUvNiJLn2kbT` via the
Figma remote MCP on 2026-08-30. Every dimension, colour and node id below was
read off a node. Nothing here is inferred.

Working renders: `scratchpad/figma-checkout/` (desktop `survey/`, mobile
`mobile/sheet1..4.png`).

---

## A. CHECKOUT — DESKTOP (12 states, all inspected)

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

| # | Node | State | What is new in it |
|---|---|---|---|
| 1 | `552:11748` | Shipping — address book EMPTY | van illustration 176², "لا يوجد عنوان شحن لديك!", add-address CTA, **continue button disabled** (`#8faef0`) |
| 2 | `549:2753` | Shipping — addresses populated | address cards 720×144/147 gap 16, edit button, default badge, selected = navy border |
| 3 | `551:10038` | Payment — nothing selected | 4 method rows 720×86 gap 16, radio 20² at x=680, notes textarea 720×120, `أضف التعليق` 122×36 |
| 4 | `554:10780` | Payment — method selected + promo panel | radio gains `check` 12² ; **Promo code container** `554:11160` 528×178 at (48,752) |
| 5 | `554:11651` | Payment — promo APPLIED | apply button turns **green**, green success line, CTA becomes `ادفع الان 1,748.98 ج.م` |
| 6 | `554:11231` | Payment — PROCESSING | centred modal + full-page scrim, 8-dot navy spinner, "لحظه من فضلك" |
| 7 | `554:12084` | **Order success** | no progress bar; centred card, illustration, order line, payment-method + ship-to rows, `الرجوع ومتابعة التسوق` |
| 8 | `554:13119` | Shipping — **branch pickup** | `أختار الفرع المناسب لك`, map-pin cards (name + address), no shipping-method cards, CTA disabled |
| 9 | `554:13750` | Shipping — **depot pickup** | `أختار أقرب موقف ليك`, **search input**, radio rows + operator chips (سوبر جيت / موقف) + car icon, disclaimer footnote |
| 10 | `557:4731` | **Add-address modal** | centred modal ~800 wide over dimmed page; 2-col form; toggle; primary + secondary actions |
| 11 | `572:15198` | Payment — InstaPay selected | same as 5 with row 3 selected |
| 12 | `586:7352` | **InstaPay transfer page** | own header + back; merchant number, masked name; phone input; **file-upload dropzone** (JPG/JPEG/HEIC); CTA `اتمام عملية الشراء` |

### Progress bar — `817:22383` (already implemented, Phase 1)
bar 1440×97 · steps inset 16 · disc 28² · glyph 20² inset 4 · connector 148×4 at
y=12, 12px clear · label 19px below · back control at x=1291 w=94 h=40.
Tokens: `bg/surface`, `border/default`, `action/primary`, `icon/inverse`,
`icon/default`, `bg/inverse` (reached connector), `bg/field` (unreached),
`text/primary`, `text/secondary`, `Label/Large` 14/500/1.3,
`Display/Lead` 20/400/1.
**No correction required** — the newly inspected states confirm Phase 1.

### Order summary column (x=0, w=624)
- container 528 wide at (48,48); inner 512, padding 8
- title 116×26 at x=372, y=24
- items container 464 wide at (24,78); each `Card - Cart Product` 464×124
  - image 108² at x=332,y=8 · **qty badge** `Value Indicator` 18² at (334,3)
  - name 308 wide · price 100×23
  - `Divider` full-width between cards at 16px gaps
- **Totals block** (payment steps only) `551:11170` 464×174:
  Subtotal 464×24 · **Savings Row** 464×24 with **info icon 16²** ·
  Divider · Shipping Row · Divider · Estimated total 464×26
- CTA `Checkout Container` 464×48

### Payment method row — `554:10948` 720×86
logo cluster at x=20 (40² or 44² tiles) · `Card info` (title + description) ·
radio/check 20² at x=680. Four methods: bank card (Mastercard/Visa/Meeza),
e-wallet (Etisalat/Orange/Vodafone Cash), InstaPay, instalments (Tru/Souhoola/valU).

### Promo code panel — `554:11160` 528×178
details at (46,24) 458×58 · input row at (24,106) 480×48 = button 138×48 +
input 326×48 with `Sale, Discount, Promotion` icon 20² at x=294 ·
`Hint Text`/`Hint Error` 360×21 **hidden** with `error-warning-line` 16².

### Notes field — `552:11505` 720×147
label 21 · textarea 720×120 · hidden `Hint Error` 360×21 with the same
`error-warning-line` 16² icon.

---

## B. CHECKOUT — MOBILE (12 frames, all inspected) — 440 wide

Mobile header is a **different composition**: top banner → utility row
(hotline · تتبع طلبك · AR) → logo row (heart/cart badges + hamburger) → search row.
No mega-menu row. `الرجوع` sits **above** the progress bar, right-aligned.

| # | Node | Height | State |
|---|---|---|---|
| 1 | `669:13034` | 1667 | **Cart** (mobile) |
| 2 | `675:17510` | 863 | **Cart empty** (mobile) |
| 3 | `675:21226` | 1554 | Checkout shipping — address book empty |
| 4 | `687:15189` | 1266 | **Add-address BOTTOM SHEET** (not a centred modal) |
| 5 | `687:16474` | 1554 | Payment |
| 6 | `687:17805` | 1798 | Payment + promo (default) — apply button reads **dark/ink**, not navy |
| 7 | `687:18104` | 1798 | Payment + promo applied (green) |
| 8 | `687:18468` | 1798 | Payment processing (same modal + scrim) |
| 9 | `687:18900` | 1042 | Order success — payment method shows **"الدفع عند الاستلام"** (COD) |
| 10 | `687:20856` | 1392 | Shipping — branch pickup |
| 11 | `687:21319` | 1596 | Shipping — depot pickup |
| 12 | `687:21691` | 1205 | InstaPay transfer |

### Desktop → mobile differences (observed, not inferred)
- **Single column.** Order summary moves BELOW the step content.
- **Sticky bottom CTA** on cart and checkout, above the browser chrome.
- Shipping-method cards **stack** instead of sitting 2-across.
- Progress bar is compact; connectors shrink; `الرجوع` moves to its own row.
- Add-address becomes a **bottom sheet**.
- Segmented labels shorten: `شحن` / `استلام من فرع` / `استلام من موقف`
  (desktop: `الشحن` / `استلام من الفرع` / `استلام من الموقف`).
- Mobile depot rows show a subtitle `أقاليم + محافظات` and **no operator chip**,
  where desktop shows a real address **and** a chip. → see §E.

---

## C. CART — DESKTOP + MOBILE

`553:4663` and `817:22551` are **both desktop cart frames**, 1440×1524, and are
near-identical. Neither is the mobile cart; the mobile cart is `669:13034`.
Navbar here is **223.42** tall — the cart KEEPS the mega-menu row that checkout drops.

```
Main Container y=282, 1440 x 806
  Order summary section  x=0    w=612
  Cart section           x=624  w=816
```

**Cart section**
- header 816×74: title at x=620 (reading start), `مسح الكل` at x=48 in **red**
- `Card - Cart Product` 720×140:
  - control column `Frame 2147236004` 83 wide — trash 32² (24px glyph) at y=22,
    **qty dropdown** `Tab - Option` 83×48 at y=80 (value + chevron 20²)
  - name 465 wide · price 133×31
  - image 108² at x=489
  - discounted item: strikethrough old price 96×23 + rule, in `text/price-sale`

**Order summary section**
- `Shipping details container` 500×88 — pin icon in 56² circle, "الشحن الي" +
  address, chevron 20² (collapsible)
- summary card 500×316 — title 114×26, Subtotal, Divider, Shipping, Divider,
  Estimated total 452×26, CTA 452×48
- `Gift Option Container` 516×159 — instalment-plans promo, illustration 88²
- **No savings row** on cart (checkout has one)

**Mobile cart `669:13034`**: title + `مسح الكل`, line cards with image at the
reading end, **`− 1 +` stepper** (NOT the desktop dropdown) and a red trash,
then `الشحن الي` collapsible, `ملخص الطلب`, instalments card, **sticky CTA**.

---

## D. SHARED COMPONENTS + ASSETS

### Already built — reuse, do not rebuild
| Thing | Where |
|---|---|
| Progress bar | `Spartrak_Checkout` + `_checkout.less` (Phase 1, verified) |
| Loading modal / 8-dot spinner | theme `_loader.less` + `_spinner.less` (§10 — the processing state is this component) |
| Empty state | `_empty-state.less` (cart-empty + no-address share it) |
| Auth modal | `spartrak.auth_modal`, opened by `#auth=<step>` |
| Cart line vocabulary | `_minicart-drawer.less` (image tile, qty badge, price) |

### Icons — verified present
`delivery-truck.svg` · `arrow-next.svg` · `plus.svg` · `phone.svg` ·
`checkout-step-cart.svg` · `checkout-step-payment.svg` · `segment-car-star.svg` ·
`segment-package-box.svg` · `shipping-fast-delivery.svg` · `pen-edit.svg`

### Icons/assets STILL to export
| Asset | Node | Used by |
|---|---|---|
| `error-warning-line` 16² | `720:26808` / `554:11204` | every inline validation message |
| `Savings info` 16² | `720:26782` | savings row tooltip |
| `Sale, Discount, Promotion` 20² | `721:30274` | promo input |
| map-pin 20² (in 40² circle) | branch cards, cart ship-to | branch pickup, cart |
| car icon | depot rows | depot pickup |
| `check` 12² | `721:30375` | selected payment radio |
| Van illustration 176² | `720:26133` | no-address empty state |
| Success illustration | `554:12084` | order success |
| Instalments illustration 88² | `817:22754` | cart promo card |
| Payment brand marks | Mastercard/Visa/Meeza, Etisalat/Orange/Vodafone Cash, InstaPay, Tru/Souhoola/valU | payment rows — **third-party artwork, never re-tint (skill rule)** |

### Design tokens missing from the theme
| Figma | Value | Note |
|---|---|---|
| `text/price-sale` | `#d54033` | strikethrough price on cart — theme has the primitive but no role token |
| `bg/accent` | `#eebd1d` | |
| `bg/brand` | `#063196` | |
| `icon/brand` | `#063196` | |
| `Shadow/Small darker` | `0 1 1.5 #14141412` | third effect; theme has only container + input |

Text styles seen: `Heading/H2` 24/700/1.3 · `Heading/H3` 20/700/1.3 ·
`Heading/H4` 18/500/1.3 · `Heading/H5` 16/700/1.3 · `Body/Large` 18/400/1.5 ·
`Body/Base` 16/400/1.5 · `Body/Base Medium` 16/500/1.5 · `Body/Small` 14/400/1.5 ·
`Body/XSmall` 12/400/1.5 · `Label/Large` 14/500/1.3 · `Label/Base` 12/500/1.3 ·
`Label/Micro` 7/700/1.3 · `Price/Base` 18/700/1.3 · `Display/Lead` 20/400/1.
All sizes already exist in `_typography.less`.

### Dynamic (Magento) vs static (Figma)
| Magento owns | Figma owns |
|---|---|
| cart items, qty, prices, totals, savings, shipping amount | every dimension, colour, type ramp, radius, shadow |
| shipping methods + delivery windows (`Spartrak_Shipping`, to build) | the method-card shape; **never hardcode names/prices** |
| customer address book, default flag, governorate list | form layout, required markers, toggle |
| payment methods + their availability | row shape, logo slots |
| coupon validity + discount amount + messages | panel shape, success/error variants |
| branch + depot locations | card/row shape (`Spartrak_PickupLocation`, later phase) |
| order number, payment method label, ship-to on success | success card shape |

---

## E. UNKNOWNS / AMBIGUITIES

1. **COD is missing from the payment list.** Mobile success (`687:18900`) shows
   `الدفع عند الاستلام`, but none of the four payment rows on any frame offers it.
   Either a fifth method is undrawn, or the success frame is illustrative.
2. **Mobile depot rows disagree with desktop.** Desktop shows a real address plus
   an operator chip; mobile shows `أقاليم + محافظات` and no chip. Which is
   authoritative is not decidable from the frames.
3. **`رقم اضافي` (additional phone)** has no native Magento equivalent — needs a
   customer-address attribute decision (custom attribute vs `fax`).
4. **No mobile frame for "shipping step with addresses populated."** Desktop has
   it (`549:2753`); the 12 mobile frames do not. Mobile address-card styling is
   therefore underspecified.
5. **InstaPay transfer is a full offline payment method** — merchant account
   details, customer phone, proof-of-transfer **file upload**, manual review. No
   backend exists. Larger than a checkout step.
6. **Promo apply button colour differs**: navy on desktop, dark/ink on mobile.
7. **Savings row** appears on checkout but not cart — confirm intended.
8. **Two near-identical cart frames** (`553:4663`, `817:22551`); which supersedes
   the other is unstated.
9. **Cart qty control differs by viewport**: dropdown on desktop, `− 1 +` stepper
   on mobile. Confirm this is deliberate rather than drift.

---

## Carried-forward follow-ups (not done, by instruction)
- Pickup Locations subsystem + two carriers — **next backend phase**.
- Post-login redirect back to checkout — needs an optional destination on the
  auth widget; deliberately untouched.
- Nothing deployed.
