# Fulfilment operating model — delivery, branch, and `استلام من الموقف`

**SpareTrak.com (ElAssal for Trading & Supply)**
Rules captured 2026-09-02 with Karim · **restructured 2026-09-03** from a
single-feature spec into the operating model for all three fulfilment channels.

> **What changed in this revision, and why.** The previous version of this file
> described `الموقف` in isolation, as though it were a feature bolted onto a
> delivery storefront. Building it revealed that it is not: the store fulfils an
> order in three genuinely different ways, and treating two of them as
> variations on the third is what produced the defects listed in §10. This file
> now states the model, the lifecycle and the exceptions for all three, and §9
> records the decisions taken on the questions the old §7 left open.

---

## 1. Three channels, not one process with options

| | **شحن** delivery | **استلام من الفرع** branch | **استلام من الموقف** depot |
|---|---|---|---|
| Who moves the goods | a courier | nobody — we hold them | a **named driver in a named vehicle** on an inter-city route |
| Where they end up | the customer's address | an ElAssal branch counter | a public transport station (`موقف`) |
| Terminal event | delivered to the door | collected at the branch | collected at the station |
| Who the customer chases | us | that branch | **the driver, directly, by phone** |
| What they must be told | when it arrives | that it is ready, and where | driver name + phone, plate, vehicle photo, origin and destination station |
| Operational risk | courier failure | goods held too long | **the customer cannot find one white microbus in a yard of thirty** |

This is why the goods movement on the depot channel is a first-class record (the
consignment) and the other two are not: it is the only channel where a **third
party we do not employ** is holding the customer's property, and the only one
where the customer's next action is to phone a stranger.

`سوبر جيت` (SuperJet, an inter-city bus company) is presented to the customer in
the same segment label but is **out of scope and deliberately not guessed**. A
bus operator has no private driver and no single identifiable vehicle, so its
tracking data is a company, a trip or ticket number and a departure time — a
different card, and its own decision.

---

## 2. The status model — two axes, deliberately separate

Conflating these two is what made the storefront lie.

**Axis A — commercial state.** Is the order accepted and paid for? This is
Magento's own `state`, used as-is: `pending_payment` → `new` → `processing` →
`complete` / `closed`, with `canceled` and `holded` off to the side. Nothing
here is invented.

**Axis B — fulfilment stage.** Where are the goods? Four stations, driven by the
four `spartrak_*` order statuses, **named in the vocabulary of the channel**:

| # | Status code | delivery | branch | depot |
|---|---|---|---|---|
| 0 | `spartrak_awaiting_approval` | بانتظار الموافقة | بانتظار الموافقة | بانتظار الموافقة |
| 1 | `spartrak_packed` | تم التعبئة | تم التعبئة | تم التعبئة |
| 2 | `spartrak_out_for_delivery` | شحنتك في الطريق اليك | **جاهز للاستلام من الفرع** | **الشحنة مع السائق** |
| 3 | `spartrak_delivered` | تم التوصيل | **تم الاستلام** | **تم الاستلام** |

Stations 0 and 1 are genuinely shared — "we have your order" and "it is packed"
mean the same thing however it leaves the building. Stations 2 and 3 are not,
and the old single vocabulary was false on two channels out of three:
`شحنتك في الطريق اليك` told a branch-collection customer something was
travelling towards them when it was sitting on a shelf, and `تم التوصيل` told a
station-collection customer that somebody had delivered their order when they
had fetched it themselves.

**No new statuses.** This changes what the four stations are *called*, not what
they are. Nothing to configure, nothing to migrate, and Figma still draws four.

### Transitions, and what drives each one

| From → To | Trigger | Automatic or human |
|---|---|---|
| — → station 0 | order placed | automatic |
| `pending_payment` → station 0 | InstaPay receipt submitted | automatic, on upload |
| station 0 → station 1 | **payment accepted**: an offline invoice is raised (InstaPay approval, or an admin invoicing COD) | human decision, automatic transition |
| station 1 → station 2 | admin sets `شحنتك في الطريق اليك` | human, **and gated on the depot channel** (§4) |
| station 2 → station 3 | admin sets `تم التوصيل` / order completes | human |
| any → `canceled` / `holded` | admin | human |

The station-1 transition is the one that was broken: registering an invoice does
not move a Magento order by itself, so approving a transfer invoiced the order
and left it in `new`/`pending` — the admin's own status field read "Pending"
beside fully invoiced items, and the customer's rail stayed at
`بانتظار الموافقة` for an order somebody had just approved.

---

## 3. Customer journey

1. Cart → **checkout delivery step** → choose one of the three segments.
2. On a pickup segment, choose a specific **branch** or **موقف**. That choice is
   snapshotted onto the order and becomes `الي الموقف`.
3. Stations 0–1: **nothing driver-related is shown.** It does not exist yet, and
   an empty driver card would read as a system fault.
4. Station 2, depot: the **`موقف` card appears** with the driver and vehicle.
5. The customer **phones the driver** to arrange the handover.
6. Station 3: the card is **retained as the collection record** (§9 Q6).

---

## 4. Admin journey — the dispatch gate

> ### Setting a **depot** order to `شحنتك في الطريق اليك` requires the vehicle and driver details. The status change is refused without them.

Required at that moment:

| # | Field | Notes |
|---|---|---|
| 1 | **Driver name** | |
| 2 | **Driver phone** | The number the customer calls. The single most important field on the card. |
| 3 | **Car plate number** | Egyptian format — Arabic letters + Arabic-Indic digits (e.g. `م ص ر ١٢٣٤`). |
| 4 | **`من الموقف`** origin station | Written per order: it depends on which vehicle is going, and from where. |
| 5 | **Car image** | A photo of the **actual vehicle**, not a stock photo. Required — see §9 Q7. |

`الي الموقف` is **displayed, and selected only when it has to be**. It is
the customer's checkout choice, so it is shown in full — name, street,
governorate, transport operator — rather than collected.

But it is not read-only, and the reason it stopped being read-only is worth
recording. The original rule was "displayed, never retyped: a second editable
copy is how the card ends up naming one station in its title and another in its
rows". That was right about *retyping* and wrong about the consequence — on an
order whose snapshot never landed there was nothing to display and no way to
supply it, so the one fact the customer needs most was unrecoverable.

What answers the original objection is that the control is a **list of the
admin-maintained depots**, not a text box: the value can only ever be a station
checkout itself offers, and every screen resolves `override ?? snapshot` through
one function, so the title and the rows still cannot disagree. Recording a
destination the checkout never saved needs no reason; **replacing** one the
customer chose does. See §9 Q5.

**Why the gate is a hard gate:** there is no notification (§6). If the status
flips without the form, the customer sees "your shipment is on its way" and
nothing else — no driver, no phone, no vehicle — while the vehicle is already
moving.

**How the gate behaves, and why that matters as much as the rule.** The refusal
**names the missing fields**, and the panel shows the same list *before* the
dispatcher acts. A gate that says only "add the driver and vehicle details",
discovered after pressing save on an order where four of five fields are already
filled, costs a dispatcher more time than the rule saves.

---

## 5. Information hierarchy

Driven by one question: **what does this person need to do next?**

### The customer's order page

| Stage | What leads | What is absent |
|---|---|---|
| 0–1, any channel | the rail, the items, the destination (so they can plan) | any driver card — the data does not exist |
| 2, delivery | the rail | — |
| 2, branch | the branch, its address and phone | driver, vehicle |
| 2, depot | **the `موقف` card, above the rail, at full width** | — |
| 3, any | the rail; the card de-emphasised but kept | — |

Within the depot card the priority is **driver phone → plate → vehicle photo →
`الي الموقف` → `من الموقف`**, because the phone is the only actionable item on
it. RTL layout: labels at the reading start, values at the reading end, vehicle
photo filling the left third.

**The reassurance strip** sits directly beneath the card — a green band with a
delivery-van illustration:

> **`الأوردر مع السواق الآن`**
> *`تأكد من وصول المنتج كما طلبته انت ولا يوجد اي نقص.`*

The card and the strip are **one unit**. Station pickup has no courier to
dispute with afterwards, so the strip tells the customer to check the goods
against the order **at the station, in front of the driver**, while there is
still someone to raise it with.

### The dispatcher's panel

Ordered by what they need first, not by what the database holds:

1. **Can this go out?** — one unmissable line: ready, or not ready *and which
   fields are missing*.
2. **Data faults**, if any — see §6.
3. **`الي الموقف` in full**: name, street, **governorate** and **transport
   operator**. `موقف السلام` alone does not identify a station — several
   governorates have similarly-named yards, and the operator decides which
   vehicles run there. The person choosing a driver for a route has to be able
   to see the route.
4. The form.

---

## 6. Exceptions — the part that is not the happy path

| Situation | Behaviour | Reasoning |
|---|---|---|
| **Depot order, no location snapshot** | Panel still renders; a prominent error names the fault, tells the dispatcher to phone the customer, and the **To station** field records the answer (§9 Q5). Dispatch is **not** blocked. Placement now also rebuilds the snapshot when the id survived anywhere, so this should only be reachable on an admin-created order. | The channel is known from the carrier, so the parcel still has to travel and the driver's number is still the customer's lifeline. Blocking the dispatch over a column *we* failed to write punishes the customer for our fault. |
| **Incomplete consignment** | Named checklist in the panel; the gate refuses and names the same fields. | One list, two consumers, so they cannot disagree. |
| **Driver or vehicle swapped after dispatch** | The consignment is updated and the change is written to the order's history, field by field, not as "saved". **Built.** | The customer's card changes under them with no notification; the history is the only trace of why, and "saved" cannot tell a corrected typo from a different vehicle. |
| **`الي الموقف` unreachable on the route** | The dispatcher selects another station on the consignment panel. A **reason is required**, and both go to the order history. **Built — §9 Q5.** | We may redirect, but not silently: the customer's own choice survives on the order, and the reason is the record of why it was not honoured. |
| **Cancelled after dispatch** | The card **stays visible**. | The goods are physically with a driver. The moment of cancellation is the worst possible time to hide his phone number. |
| **On hold** | Rail replaced by `الطلب موقوف مؤقتاً`. | A pause the customer can do nothing about; pretending to be at a station would be worse. |
| **Payment rejected (InstaPay)** | Order stays open, comment added, customer contacted by phone. | Nothing in the flow may claim money arrived. |
| **Vehicle photo missing, everything else present** | Refused, with the photo named. | See §9 Q7 — this is the decision most worth revisiting. |

---

## 7. Lifecycle & notification

| Stage | `موقف` card |
|---|---|
| بانتظار الموافقة | **hidden** — the data does not exist yet |
| تم التعبئة | **hidden** |
| **الشحنة مع السائق** | **visible** — populated at the moment of the status change |
| تم الاستلام | visible, retained as the collection record |

**Notification: on-site only.** The customer must open the site. **No WhatsApp,
no SMS.**

> This remains a deliberate decision, and it remains the weakest point in the
> model. It is the one moment in the whole funnel where the information is
> time-critical, because the vehicle is already on the road. It is the obvious
> candidate for the platform's first notification, and until it exists the
> dispatch gate is the only thing standing between a customer and a shipment
> they cannot find.

---

## 8. Data model

| Record | Holds | Lifetime |
|---|---|---|
| `sales_order.shipping_method` | **which channel** — core's column, written on every order | the order's |
| `sales_order_address.spartrak_pickup_*` | **which place** — type, id, name, address, snapshotted | the order's |
| `spartrak_pickup_consignment` | driver, phone, plate, origin station, vehicle photo | the order's |
| `spartrak_pickup_branch` / `_depot` / `_operator` | the live network | maintained in admin |

**The channel is derived from the carrier, not from our own snapshot.** This was
the single most consequential correction in this revision. The snapshot is one
extra copy that can fail to land, and when it did, every consumer read "home
delivery" — so the only screen that can record the driver **did not render at
all**. The feature disappeared in exactly the case that needed it. The carrier
column is core's, is written on every order, and this module cannot fail to
populate it.

**Name and address travel with the id** so an order still reads correctly after
a depot is renamed or removed. An order is a financial record.

---

## 9. Decisions on the previously open questions

| # | Question | Decision |
|---|---|---|
| 1 | Does **`سوبر جيت`** get its own card and fields? | **Out of scope, unchanged.** It needs its own carrier, its own fields (company, trip number, departure time) and its own frame. Nothing pretends to cover it. |
| 2 | Where does the `موقف` list come from? Filtered by governorate? | **Admin-maintained depot records, one flat searchable list.** The picker searches name, address, governorate and operator with Arabic orthographic folding, which does the work a governorate filter would without a second control the design does not draw. |
| 3 | Does pickup change the shipping fee? | **Per-carrier configuration, currently zero.** Each pickup carrier has its own price field in Delivery Methods, so this is a merchant setting rather than a code decision. |
| 4 | Plate-number validation — free text or structured? | **Free text.** A structured Egyptian input (3 Arabic letters + 4 Arabic-Indic digits) would reject the legitimate variants a dispatcher copies off a real plate. The field is read by a human at a station, not parsed. |
| 5 | Can the admin override `الي الموقف`? | **Yes — ✅ BUILT 2026-09-04.** A nullable override on the consignment (`destination_id`, `destination_name`, `destination_address`, `destination_reason`), defaulting to the snapshot, written to the order history. Every consumer reads `override ?? snapshot` through one resolver (`Model\OrderDestination`), so title and rows still cannot disagree. **The policy it encodes:** recording a destination the checkout never saved needs no reason — nothing is being overruled; *replacing* a station the customer chose does, and the reason is the customer's only record of the change. The field is a **list of admin-maintained depots**, not free text, so the value can only ever be a station checkout itself offers. |
| 6 | Is the card retained after collection? | **Retained.** It is the customer's record of who handed the goods over, and disputes happen after collection. De-emphasised, not removed. |
| 7 | *(new)* Must the vehicle photo block dispatch? | **Currently yes.** §4 is explicit that the photo exists so the customer can identify one vehicle in a yard of near-identical ones, and the plate alone is a poor substitute. **Worth revisiting:** it is also the field a dispatcher is most likely to be unable to supply on time, and refusing an otherwise-complete dispatch has its own cost. |

---

## 10. Defects this model corrected

Recorded because each one was caused by the old single-channel framing, not by a
coding slip:

1. **The dispatcher's form was invisible on real depot orders.** Channel derived
   from our own snapshot instead of the carrier; snapshot empty ⇒ "delivery" ⇒
   no panel, no way to enter the driver at all.
2. **The rail lied on two channels.** "On its way to you" for a parcel on a
   branch shelf; "delivered" for goods the customer fetched.
3. **The destination panel could vanish entirely** on a pickup order whose
   snapshot was missing, because it printed the snapshot alone.
4. **The heading never named the channel** — one "Collection point" for both
   pickup kinds, and a public transport station is not a collection point.
5. **Approving payment did not move the order**, so both the admin and the
   customer's rail showed "pending" on an invoiced order.
6. **The gate named no fields**, so the only way to find the missing one was to
   guess and re-save.
7. **A missing destination was unfixable.** The panel asked whether the
   CHECKOUT snapshot had landed — an answer that can never change once it is
   false, because nothing rewrites a placed order's address. So the red
   "the destination station is missing from this order" box stood on the order
   for ever, told the dispatcher to phone the customer, and offered nowhere to
   put what they were told.

### Still outstanding

| Piece | State |
|---|---|
| `الي الموقف` admin override + reason + history (§9 Q5) | ✅ built 2026-09-04 |
| Driver/vehicle swap written to order history (§6) | ✅ built 2026-09-04 |
| Snapshot rebuilt at order placement so the fault stops recurring | ✅ built 2026-09-04 |
| Any notification at all (§7) | ⬜ business decision — **now the single weakest point in the model** |
| `سوبر جيت` (§9 Q1) | ⬜ out of scope |
| A destination on an **admin-created** depot order | ⬜ the admin order-create screen offers the depot carrier but no station picker, so such an order lands with no destination and is repaired by hand on the consignment panel. Acceptable today because admin-raised depot orders are rare; worth a picker if that changes. |

---

*Canonical source: `BUSINESS.md` §12 in the ElAssal e-commerce project folder.
This file is the working copy the implementation is written against — if the
rules change, §12 is the one that must be updated too.*
