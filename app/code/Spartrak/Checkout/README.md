# Spartrak_Checkout

The SpareTrak checkout, built to the Figma "Checkout" frames in file
`6FRlQfPIncVUvNiJLn2kbT`. It **extends** Magento's own one-page checkout — it does
not replace it. Every quote, address, rate, payment and order operation stays
core's; this module owns the step model and the presentation the design asks for.

## Why the module exists at all

The theme already owns Magento_Checkout *presentation* (the cart drawer lives in
`Spartrak/spartrak/Magento_Checkout/`). Three things in this design are not
presentation, so they cannot live in a theme:

1. **A three-marker progress bar.** Core's has two, because core's step navigator
   only knows about the steps registered inside checkout — `shipping` and
   `payment`. Figma opens with **عربة التسوق** (Cart), which is the page *before*
   checkout. That marker is a permanently-complete link back to the cart, not a
   step, so it cannot come from `stepNavigator.steps()`.
2. **A delivery-mode segmented control** — الشحن / استلام من الفرع / استلام من
   الموقف. Only the first has any backend in Magento Open Source.
3. **Shipping methods carrying a delivery window** ("٥–٧ أيام عمل"), which no
   core carrier exposes.

## Scope, and what is deliberately not here yet

| Delivery mode | State |
|---|---|
| الشحن (ship to address) | Built |
| استلام من الفرع (branch pickup) | Segment renders per Figma, inert — needs `Spartrak_PickupLocation` |
| استلام من الموقف (depot pickup) | Segment renders per Figma, inert — needs `Spartrak_PickupLocation` |

Magento Open Source has no in-store pickup (it is an Adobe Commerce feature), so
both pickup modes need an admin-managed location entity and a carrier apiece.
That is a separate module by design: a pickup location is a merchant's *data*,
useful to the branches page and the footer as much as to checkout, and it has no
business being coupled to a checkout step. Until it lands, the two segments are
rendered exactly as the design draws them and disabled — the alternative was
deleting them from the markup, which would have made an unfinished checkout look
finished.

The delivery-window rates come from `Spartrak_Shipping`, which is likewise its own
module: a carrier is not checkout UI, and it applies to the cart's shipping
estimator and the admin order-create screen too.

## Guest checkout

Guest checkout is disabled on this store. Core handles that by queueing a red
toast — "Guest checkout is disabled." — and bouncing the shopper to the cart,
which names a policy instead of offering the action that resolves it.

`Plugin\PromptLoginForGuest` replaces it with the theme's existing sign-in
modal. No new markup and no new JavaScript: `spartrak.auth_modal` already
renders on every page, and its widget already opens from an `#auth=<step>`
fragment, so the fix is the redirect's fragment and nothing else. The widget
clears that fragment once consumed, so the post-login reload does not re-open
it.

## Layout

```
view/frontend/
  layout/checkout_index_index.xml   step model + component wiring
  web/js/view/                      Knockout components
  web/template/                     their templates
```

Styling is **not** here. It lives in the theme, at
`Spartrak/spartrak/web/css/source/components/_checkout.less`, because CLAUDE.md
§2/§7 put presentation in the theme and this module only supplies the structure
and behaviour that core does not have.
