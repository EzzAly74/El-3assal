# Spartrak_Contact

Owns the storefront's contact details, and removes Magento's contact **page**
in favour of a dialog.

## What it does

1. **Holds the contact details as store-view-scoped configuration** —
   `Stores > Configuration > General > Contact Details`. WhatsApp, hotline,
   showroom lines, email, address, a directions URL and opening hours.
2. **Exposes them to the theme through one ViewModel**
   (`ViewModel\ContactDetails`), which returns ready-to-render rows and omits
   any row whose value is unset.
3. **Intercepts `/contact/` and `/contact/index/index/`** (GET only) and
   redirects back to the page the shopper came from with `#dialog=contact`, so
   the Figma contact dialog opens in place instead of a form page rendering.

## Where the UI lives

Nothing in this module renders anything. The theme owns all of it:

| Concern | File |
| --- | --- |
| Markup | `Spartrak/spartrak/Magento_Theme/templates/html/contact-dialog.phtml` |
| Styling | `Spartrak/spartrak/web/css/source/components/_dialog.less` |
| Behaviour | `Spartrak/spartrak/web/js/spartrak-dialog.js` |
| Wiring | `Spartrak/spartrak/Magento_Theme/layout/default.xml` |

The footer reads the same ViewModel for its hotline row and its bottom-bar
phone and email, so there is one place a merchant changes a number.

## Figma

`1179:24007` — "Modal - Contact", 560×672.

## What it deliberately does not touch

- **`contact/index/post`.** The POST endpoint behind Magento's form still
  works, and Magento's own Contact configuration (recipient, sender identity)
  is untouched. The design replaces the form with direct channels; the endpoint
  is left standing so that re-introducing a form later is a template change.
- **`general/store_information`.** See `registration.php` for why the native
  store-information group could not carry this panel.

## Defaults

`etc/config.xml` ships ElAssal's real published contact details, so the dialog
is correct on a fresh install with no admin setup. `directions_url` is the one
exception and ships empty — the "Directions" line only renders once a merchant
has supplied a real map URL.
