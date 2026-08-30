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
