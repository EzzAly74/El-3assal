# RTL Compatibility Layer — `Spartrak/spartrak_rtl`

**Installed:** 2026-08-24 · **Files:** 59 · **Origin:** `Smartwave/porto_rtl`

## Why this exists

`Spartrak/spartrak_rtl`'s parent was changed from `Smartwave/porto_rtl` to
`Spartrak/spartrak` so that shared implementation lives in exactly one place
(see `theme.xml` and `.claude/docs/10-THEME-ARCHITECTURE.md`). That change was
correct for Spartrak's own code, but it also removed `porto_rtl` from the
fallback chain — and `porto_rtl` carried **RTL layout corrections for every
Magento/Porto page Spartrak has not rebuilt yet**.

Measured impact of the gap before this layer was installed:

- **544 compiled CSS rule-blocks** present in `porto_rtl`'s `styles-m.css` but
  absent from `spartrak_rtl`'s
- **3,231 differing LESS source lines** across 59 files

Examples of what was broken (all genuine direction bugs, not cosmetics):

```css
.minicart-wrapper .block-minicart          { left: 0; }   /* flyout on wrong side */
.table-paypal-review-items .col.subtotal   { text-align: right; }
.porto-links-block li.porto-links-item i   { margin-left: 12px; float: right; }
.product-type-carousel … .product-info-main { float: left; right: 0; }
.prev-next-products .product-nav .product-pop { left: 0; }
.products-grid.wishlist .product-item-checkbox { right: 20px; }
```

`dir="rtl"` does **not** fix these. Browsers only auto-mirror *logical*
properties (`margin-inline-start`, `inset-inline-start`, …). Porto's rules use
*physical* `left`/`right`/`float`, so they need explicit RTL overrides.

## What these files are

Every file here was selected by byte-comparing `porto_rtl`'s copy against
`Smartwave/porto`'s equivalent. Only files that **genuinely differ** (or exist
only in `porto_rtl`) were installed — 23 files that were identical to `porto`
were deliberately excluded, since they carry no RTL value.

They sit at the same relative paths they had in `porto_rtl`, so Magento's
`@magento_import 'source/_module.less'` collection and normal theme fallback
pick them up exactly as before.

## These are NOT duplication

The project rule is: *a file duplicated between `spartrak` and `spartrak_rtl` is
a bug; a file that exists only in `spartrak_rtl` because it is genuinely
direction-specific is correct.* These 59 files are the latter — none of them has
a counterpart in `Spartrak/spartrak`.

## Retirement plan — delete per phase, do not bulk-convert

Each file becomes dead the moment Spartrak rebuilds the page it styles, because
Spartrak components use logical properties and need no RTL override. Retire in
this order, deleting only after the rebuilt page is verified in **both** store views:

| Phase | Rebuild | Then delete |
|---|---|---|
| 7 — PDP | product view, gallery, price | `Magento_Catalog/**`, `Magento_Msrp`, `Magento_Review`, `Magento_SendFriend`, `WeltPixel_Quickview/**`, `Magento_Swatches`, `Magento_GroupedProduct`, `Magento_Downloadable`, `Magento_Bundle` |
| 8 — Cart | cart, minicart | `Magento_Checkout/…/_cart.less`, `…/_minicart.less`, `Magento_GiftMessage`, `Magento_GiftWrapping` |
| 9 — Checkout | checkout flow | remaining `Magento_Checkout/**`, `Magento_Paypal`, `Magento_Multishipping`, `Magento_AdvancedCheckout/**` |
| 10 — Account | account pages | `Magento_Customer/**`, `Magento_Sales/**`, `Magento_Wishlist`, `Magento_MultipleWishlist`, `Magento_Rma`, `Magento_GiftCard`, `Magento_GiftRegistry`, `Magento_Newsletter` |
| 6 — Catalog/PLP | already rebuilt | `Magento_LayeredNavigation`, `Magento_CatalogSearch` — verify layered-nav RTL first |
| n/a — out of scope | features not used | `Magefan_Blog`, `Smartwave_Socialfeeds`, `Smartwave_Filterproducts`, `Smartwave_Megamenu` (nav already replaced) — safe to drop once confirmed unused |

**Do not** convert these files' physical properties to logical ones in place.
They are vendor RTL overrides that pair with Porto's physical-property LTR rules;
converting one side alone breaks both directions. Delete them wholesale when the
page they serve is replaced.

## Regenerating / verifying this layer

```bash
# list the genuine-RTL set (differs from porto, or porto_rtl-only)
cd app/design/frontend
for f in $(cd Smartwave/porto_rtl && find . -path './web/css/source/*' -prune -o \
           -type f \( -name '*.less' -o -name '*.css' \) -print | sed 's|^\./||' | sort); do
  if [ -f "Smartwave/porto/$f" ]; then
    diff -q "Smartwave/porto/$f" "Smartwave/porto_rtl/$f" >/dev/null 2>&1 || echo "$f"
  else
    echo "$f"
  fi
done
```

Note `Smartwave/porto_rtl` also received an asset back-fill earlier (files copied
in from `Smartwave/porto` to repair a broken static-content deploy). Those copies
are byte-identical to `porto`'s and are therefore excluded by the comparison
above — which is exactly why the comparison is byte-based rather than path-based.
