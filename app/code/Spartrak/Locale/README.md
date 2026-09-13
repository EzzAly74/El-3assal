# Spartrak_Locale

Pins every number this store formats to the **Latin** numbering system.

## Why

Arabic-Indic numerals (٠١٢٣) were rejected by the client for the Arabic locale
as well as the English one — it is a stated non-negotiable, not a preference.
ICU disagrees by default: the numbering system for `ar_EG` is `arab`, so Magento
rendered every price as `٠٫٠٠ ج.م.‏` — digits *and* separators.

Verified on the server before writing any of this:

```
ar_EG@currency=EGP               ->  Arabic-Indic digits
ar_EG@currency=EGP;numbers=latn  ->  Latin digits
```

The whole fix is one ICU keyword. All the work is in applying it everywhere.

## The three seams

Magento builds ICU formatters at three points, and **only one of them goes
through a factory**, so a single preference does not cover it.

| # | Where | What it renders | How it is reached |
|---|---|---|---|
| 1 | `Magento\Directory\Model\Currency::getNumberFormatter()` | every server-rendered price — cart, minicart section data, checkout totals, order emails, admin order views | `preference` on `Magento\Framework\NumberFormatter` (the generated factory resolves through the object manager) |
| 2 | `Magento\Framework\Currency\Data\Currency::toCurrency()` | the fallback used when `canUseNumberFormatter()` returns false — any option outside `{precision, display, symbol}` | `preference` on `Magento\Framework\Currency`, fixing the locale at construction |
| 3 | `Magento\Framework\Locale\Format::getPriceFormat()` | `decimalSymbol` / `groupSymbol` handed to the browser, used by `Magento_Catalog/js/price-utils` | plugin, correcting only those two values |

Seam 3 is the one that is easy to miss. JavaScript numbers are always Latin, so
KO-rendered prices were never going to show ٠١٢٣ — but they take their
separators from `priceFormat`, which in `ar_EG` are ٫ (U+066B) and ٬ (U+066C).
Fixing only PHP produces the worst outcome: `1٬234٫50` next to a server-rendered
`1,234.50` on the same page.

## Trailing `.00` — seams 5 and 5b

Not a numbering-system question at all, but it lives here because this module
owns how the store writes a number. The rule: **a whole price shows no
decimals** (`14,109 ج.م`), a price with real fractions keeps them (`850.50`).

| # | Where | What it renders | How it is reached |
|---|---|---|---|
| 5 | `Magento\Directory\Model\Currency::formatTxt()` | every price the SERVER formats — category grid, homepage rails, order emails, admin order views | `Plugin\WholePricePrecision`, setting `precision => 0` before ICU formats |
| 5b | `Magento_Catalog/js/price-utils` | every price the BROWSER formats — the PDP (price-box re-renders it on load), the cart summary, the checkout summary, the minicart | mixin, `view/frontend/web/js/price-utils-precision-mixin.js` |

Seam 5b is the one that was missing, and its symptom was oddly specific: a
product's PLP tile read `1,087 ج.م` while its own PDP read `1,087.00 ج.م`. Both
come from the same `getFinalPrice()`; the difference is that
`Magento_Catalog/js/price-box` overwrites the server's markup on `_create`, so
the PDP price is a client-formatted one whatever the server sent. The cart and
checkout summaries are Knockout and were never server-formatted at all.

The precision cannot simply be pinned in seam 3: `priceFormat` is one object
shared by every price on the page, so `requiredPrecision: 0` there would render
850.50 as `851`. It has to be decided per amount, which means at the call.

Seam 5b is **frontend only**, unlike seam 5. price-utils is also used by admin
product-form price fields, where reformatting a value someone is about to edit
is a change nobody asked for; the admin's server-rendered prices already follow
the rule through seam 5.

## What was deliberately NOT done

**A plugin on `Magento\Framework\Locale\ResolverInterface::getLocale()`.** It is
the obvious one-line fix and it breaks the store. `Magento\Framework\Translate`
uses that same return value to locate translation files, so every lookup would
hunt for `ar_EG@numbers=latn.csv`, find nothing, and drop the storefront back to
untranslated English. The locale string has two jobs and only one of them wants
the keyword — which is why the keyword is applied at the formatters and nowhere
upstream of them.

## Scope

Global (`etc/di.xml`), not frontend-only. An admin reading a total in ٠١٢٣ while
the customer's email says 0123 is the same defect from the other side, and
reconciling the two by eye is how a refund gets mis-keyed.

A locale that already names a numbering system is passed through untouched — an
explicit caller wins, so this module never becomes the reason something is hard
to debug.
