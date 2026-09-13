# Spartrak_CustomerAccount

Read-side services for the SpareTrak **My Account** section. The markup and the
styling live in the `Spartrak/spartrak` theme; this module only answers
questions about a signed-in customer's own data, plus the one state-changing
action Magento does not ship.

Figma (file `6FRlQfPIncVUvNiJLn2kbT`):

| Screen | Desktop | Mobile |
|---|---|---|
| حسابي — account information, read | `562:10229` | `689:19897` |
| حسابي — account information, edit | `562:17237` | `689:33229` |
| طلباتي — order history | `562:17527` | `689:33541` |
| عناويني — address book | `562:18126` | `690:39059` |
| تتبع الطلب — order page | `562:18903` | `690:34858` |
| … shipped state | `562:19640` | `690:36711` |
| … InstaPay payment | `572:15611` | — |

## What is in here

| Class | Job |
|---|---|
| `ViewModel\AccountMenu` | The five signed-in destinations plus sign-out, shared by the desktop header's account menu and the mobile drawer's account disclosure. One declaration, two consumers. |
| `ViewModel\Navigation` | The count in the rail's "طلباتي" badge, filtered to the same statuses the order list shows. |
| `ViewModel\Profile` | Name / email / phone for the account card, with Spartrak_CustomerAuth's placeholder-email rule applied. |
| `ViewModel\AddressBook` | The whole address book as one list, default first, with the card's title, address line and action URLs. |
| `ViewModel\OrderView` | Every fact the order-history card and the order page share — status, estimated arrival, payment title, destination, item rows and images, money. |
| `Model\OrderProgress` | Magento's eight order states mapped onto the four stations Figma draws, plus the completion ratio the rail is filled with. |
| `Controller\Address\SetDefault` | "تعيين كافتراضي" on an address card — a POST, because it changes state. |
| `view/frontend/web/js/email-change.js` | Reveals the current-password confirmation when the email on the account card is actually changed. |

The estimated arrival date itself is derived by `Spartrak\Shipping\Model\EstimatedArrival`,
which lives with the delivery windows it reads.

## Decisions worth knowing before changing this

- **The four stations are not order statuses.** `Model\OrderProgress` maps
  states and the existence of a shipment onto them, so a merchant renaming a
  status cannot desynchronise the tracker from reality. A cancelled or on-hold
  order is *off the rail* and renders its own status label instead.
- **There is no address nickname.** Figma's cards read like nicknames, but the
  address form the design draws (the checkout modal, `557:5173`, which the
  address book reuses) collects six fields and none of them is a label. The
  cards are titled with the recipient name. Adding a nickname needs a Figma
  frame for the field first — see `ViewModel\AddressBook`.
- **The phone number is not editable.** It is the sign-in identifier
  (Spartrak_CustomerAuth), it is uniquely indexed, and the only verified paths
  that touch it are signup and password reset. See the theme's
  `Magento_Customer/templates/account/dashboard/info.phtml`.
- **Setting a default sets BOTH billing and shipping.** This storefront collects
  one kind of address and shows one "default" badge; see
  `Controller\Address\SetDefault`.
- **Order-item images load once per order, not once per line.**
  `ViewModel\OrderView` primes a per-order product map on the first
  `getItemImage()` call.
