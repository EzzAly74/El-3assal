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

## Order statuses — the two axes, and how each one moves the other

§2 of the spec is built on two axes being kept apart:

| | |
|---|---|
| **Axis A — commercial state** | is it accepted and paid for? Magento's own `state`, used as-is |
| **Axis B — fulfilment stage** | where are the goods? the four `spartrak_*` statuses |

Magento stores both in the same column — the order's `status` — which is where
every defect in this area came from.

### The four stations and the states they may stand in

| status | station | states |
|---|---|---|
| `spartrak_awaiting_approval` | `بانتظار الموافقة` | `pending_payment`, `new` |
| `spartrak_packed` | `تم التعبئة` | `processing` |
| `spartrak_out_for_delivery` | `شحنتك في الطريق اليك` | `processing`, `complete` |
| `spartrak_delivered` | `تم التوصيل` / `تم الاستلام` | `complete`, `processing` |

`Model\DeliveryStatus` is the single definition; `Setup\Patch\Data\AddDeliveryStatuses`
creates the statuses and `ReassignDeliveryStatusStates` re-asserts the
assignments on installations where the first patch has already run.

**Why more than one state each, and the bug that forced it.** `spartrak_delivered`
was assigned to `complete` alone. Every invoiced, unshipped order sits in
`processing` — and Magento builds the status dropdown from
`getStateStatuses($order->getState())`, while
`Controller\Adminhtml\Order\AddComment` **silently discards** a posted status
that is not assigned to the current state (`getOrderStatus()` returns the
order's existing status instead of raising anything). So the dispatcher was
offered Processing, Suspected Fraud, Packed and Out for delivery, there was no
way to mark the order collected, and no error explaining why.

`complete` is on the out-for-delivery row because an admin may raise the
shipment at dispatch, which flips the state while the goods are still moving.

### Axis B follows Axis A: payment accepted moves the order

`Observer\StampPackedOnPaymentApproval`, on `sales_order_invoice_register`.

§2 says station 0 → station 1 happens on payment acceptance, automatically, and
§10 lists "approving payment did not move the order" as a corrected defect. Only
half of it was: the customer's rail was right because `Model\OrderProgress`
falls back to `state === processing`, while the order itself never moved and the
admin's own status field still read "Processing" beside fully invoiced items.

It never moves an order backwards — a second invoice on an order already out
with a driver does not reset the rail to "packed".

### Axis A follows Axis B: collection completes the order

`Observer\CompleteOrderOnCollection`, on `sales_order_save_commit_after`.

Setting `تم الاستلام` moved Axis B and left Axis A where it was. Magento only
enters `complete` when an order is fully invoiced **and** fully shipped, and
nothing in this flow ever raised a shipment — so the order stayed `processing`
for ever, the grid kept saying Processing, the "Ship" button kept sitting there,
and no report counted the sale as finished.

Reaching the last station now raises the shipment that takes it to `complete`.

**At collection, not at dispatch**, and that is the one channel-sensitive
decision here: on `استلام من الفرع` the parcel at station 2 is
`جاهز للاستلام من الفرع` — on a shelf in our own branch, not shipped anywhere.
Collection is the one event that means the same thing on all three channels.

`_save_commit_after` and not `_save_after`: creating a shipment **writes**, and
doing that inside the transaction of the save that triggered it is how a nested
rollback loses both.

### And the stage survives Magento's own transitions

`Plugin\Sales\KeepFulfilmentStage`, a before/after pair on
`ResourceModel\Order\Handler\State::check()`.

That handler runs on **every** order save, and when it decides the state has
moved it does this:

```php
$order->setState(Order::STATE_COMPLETE)
      ->setStatus($order->getConfig()->getStateDefaultStatus(...));
```

It overwrites the status with the new state's default. So invoicing an order
wiped `تم التعبئة` and shipping one wiped `شحنتك في الطريق اليك` — taking the
customer's rail and the driver card's visibility with them, because both read
the status.

This **cannot** be an observer: `sales_order_save_before` is dispatched by
`AbstractModel::beforeSave()`, which runs *before* the resource model calls that
handler, so by the time any observer could look the original status is already
gone. The `before` half records the status the caller intended; the `after` half
restores it if the handler replaced it and the station is legal in the state the
handler settled on. Capturing the intent beforehand is what makes it safe —
reading `getOrigData('status')` afterwards gives the *previous* status, so an
admin deliberately moving an order to `delivered` would have their choice
reverted.

Only the status is ever restored, never the state, and only one of this module's
four.

### The consignment form waits for payment

`Model\PaymentApproval` — an invoice exists, which is what §2 calls payment
acceptance on this store ("an offline invoice is raised: InstaPay approval, or
an admin invoicing COD").

The form used to be available from the moment the order existed, which let a
dispatcher assign a named driver — and publish his phone number on the
customer's order page — to an order nobody had been paid for. The panel now
disables the fieldset until then (native HTML: the values stay readable, nothing
can be typed, and a disabled fieldset is not submitted) and
`Controller\Adminhtml\Consignment\Save` refuses the post, because a disabled
fieldset is a courtesy and anyone can post the form anyway.

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
