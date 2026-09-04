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

## The consignment — `استلام من الموقف` only

A depot order is the one channel where a **third party we do not employ** is
holding the customer's property, and the only one where the customer's next
action is to phone a stranger. So the goods movement is a first-class record:
`spartrak_pickup_consignment`, one row per order, holding the driver, their
phone, the plate, the origin station and a photograph of the actual vehicle.

| Piece | What it does |
|---|---|
| `Block\Adminhtml\Order\View\Consignment` + `templates/order/consignment.phtml` | the dispatcher's panel on the admin order view: can this go out, what is still missing, where is it going, and the form |
| `Controller\Adminhtml\Consignment\Save` | one multipart POST — and the order-history entry for what changed |
| `Observer\RequireConsignmentBeforeDispatch` | **the gate**: a depot order may not BE `شحنتك في الطريق اليك` without a complete consignment |
| `Model\ConsignmentRequirements` | the missing-field list, read by BOTH the panel and the gate so they cannot disagree |
| `Model\ConsignmentAudit` | turns a save into a sentence for the order's history |
| `ViewModel\OrderConsignment` | the customer's card, visible from `الشحنة مع السائق` onward |

**The gate is an observer on `sales_order_save_before`, not a repository
plugin.** The admin's own status-change screen calls `$order->save()` directly
and never touches the repository, so a repository plugin would have been
bypassed by the exact screen the rule exists for.

**Every save is written to the order's comment history**, not visible on the
front. Notification is on-site only, so a customer's card changes under them
silently; when they ring to ask why the number they wrote down no longer works,
the history is the only place the answer exists.

## `الي الموقف` — the destination, and the override

The destination is normally the customer's own checkout choice, snapshotted onto
`sales_order_address.spartrak_pickup_*`. **That snapshot is never rewritten.**

Two situations need more than it, and both are handled by four nullable columns
on the consignment plus one resolver:

| Situation | What happens |
|---|---|
| The snapshot never landed — the order was placed against the depot carrier but carries no station | The panel says so and offers the **To station** field. The dispatcher phones the customer and records what they confirm. No reason required: nothing is being overruled. |
| The chosen station is unreachable on the route | The dispatcher selects another. A **reason is required**, and both the change and the reason go to the order history. |

`Model\OrderDestination` resolves `override ?? snapshot` for **every** consumer
— the customer's order page, the success page, the driver card, the admin order
view and the dispatcher's panel all go through `ViewModel\OrderPickup`, which
goes through it. That is what makes it impossible for the card's title and its
`الي الموقف` row to name two different stations.

The field is a **list, not a text box.** A destination can only ever be one of
the stations checkout itself offers, so the two sides cannot invent different
spellings of one place, and the customer's card gets a real name, street,
governorate and operator instead of one typed string. A station missing from the
list is a station to add under Pickup Locations — which fixes checkout at the
same time. (`origin_station` is free text, and deliberately so: it is a fact
about the journey, not a place in our network.)

Selecting nothing means *use the customer's choice*; selecting the customer's
own station clears the override rather than storing a second copy of it.

## The snapshot check at placement

`Observer\CompletePickupSnapshotOnPlacement`, on
`sales_model_service_quote_submit_before`.

The channel comes from core's `shipping_method` and the place comes from this
module's snapshot, and those are two independent mechanisms — so an order can
say "collected from a station" while naming no station. That state is what made
the dispatcher's panel disappear entirely once, and what leaves a customer with
no destination on their order page.

The observer is the invariant stated at the one moment where the quote and the
order are both in hand and neither is written yet. It **repairs and never
refuses**: it re-resolves the location from the id (on the order address, or on
the quote address if the copy is what failed) and writes all four columns; if
there is no id anywhere — an admin-created order, for instance — it logs the
fault with the increment id and lets the order through, because a checkout that
dies at the last step is worse than an order a dispatcher can still complete by
hand.

## Not included

The storefront UI for these lists lives in the theme
(`Magento_Checkout/web/template/shipping-address/`), because it is presentation.
This module publishes the data into `window.checkoutConfig` via
`Model\ConfigProvider` and reads the choice back off the shipping-information
payload.
