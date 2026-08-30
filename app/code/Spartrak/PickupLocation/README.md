# Spartrak_PickupLocation

Admin-managed collection points, and the two shipping carriers that offer them.

Implements the two pickup states of the Figma checkout — branch (`554:13119`)
and depot (`554:13750`) — end to end: the entities behind them, the admin
screens that maintain them, the carriers that put them on the checkout, and the
persistence that carries the shopper's choice through to the order.

## What it owns

| Entity | Table | Fields beyond the shared set |
|---|---|---|
| Branch | `spartrak_pickup_branch` | `phone` |
| Depot | `spartrak_pickup_depot` | `operator_id` |
| Operator | `spartrak_pickup_operator` | `code` (stable identifier) |

Branches and depots share name / address / governorate / enabled / sort order,
each in an Arabic and an English column. Arabic is the required one — see
`Controller\Adminhtml\*\Save`.

**Admin:** Stores → Pickup Locations → Branches / Depots / Transport Operators.
Three ACL resources so a branch manager need not be able to retire an operator.

**Carriers:** `spartrak_branch` and `spartrak_depot`, configured under
Stores → Configuration → Sales → Delivery Methods. Each offers a single method
and **declines to offer it at all when its location list is empty** — a pickup
option that leads to an empty list is a dead end.

## Three decisions worth knowing before you change anything

**Governorates are Magento's own `directory_country_region` rows,** added by
`Setup\Patch\Data\AddEgyptGovernorates` because Magento ships no Egyptian
regions. That means the depot form, the customer address form and any future
tax rule all read one list, and a depot in Giza holds the same `region_id` a
shopper in Giza does.

**The plugin is registered in `etc/di.xml`, not `etc/frontend/di.xml`.** The
checkout reaches `saveAddressInformation()` over REST, which runs in
`webapi_rest`. A frontend-scoped plugin would never fire on a real checkout
while appearing to work in any test that called the service directly.

**The order stores the location's name and address as well as its id.** An
order is a financial record; renaming a branch next year must not rewrite what
a shopper bought today. `etc/fieldset.xml` copies all four columns from
`quote_address` to `sales_order_address` using Magento's own converter.

## The pickup address

Figma's pickup frames have no address form — the location list replaces the
address list. But an order still needs somewhere to go, so
`Plugin\Checkout\ApplyPickupLocation` builds the shipping address out of the
chosen location (street = its address, city = its governorate) while preserving
the customer's own name and telephone. This is what Magento's in-store-pickup
module does, for the same reason.

The postcode is deliberately left **empty** rather than filled with a
placeholder: a fabricated postcode gets printed on a label as though it meant
something. Whether one is required is the store's own
`general/country/optional_zip_countries` setting to state.

## Not included

The storefront UI for these lists lives in the theme
(`Magento_Checkout/web/template/shipping-address/`), because it is presentation.
This module publishes the data into `window.checkoutConfig` via
`Model\ConfigProvider` and reads the choice back off the shipping-information
payload.
