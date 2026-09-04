# Spartrak_CustomerAddress

Adds `additional_phone` (رقم اضافي) to customer addresses, quote addresses and
order addresses.

Figma's add-address form asks for a second contact number — desktop modal
`557:4731`, mobile bottom sheet `687:15189`. In this market a delivery is
routinely arranged with whoever answers, so it is a real fulfilment field.

## Not `fax`

`fax` is an unused core column of roughly the right shape. Reusing it would
mean the admin labels a phone number "Fax", every export carries it as a fax,
and the next developer has to be told. A column costs nothing and says what it
is.

## A static column, like every other address field

`telephone`, `city`, `company` and `postcode` are all real columns on
`customer_address_entity` with `backend_type = static`. This follows them, so
no EAV join is added to a path the checkout renders on every load, and the
value behaves like `telephone` everywhere that already handles addresses.

Both halves are required and do different jobs:

- `etc/db_schema.xml` creates the columns.
- `Setup/Patch/Data/AddAdditionalPhoneAttribute` creates the EAV attribute row
  and lists the forms it appears on.

Declaring `backend_type` as `varchar` instead of `static` would make Magento
write to `customer_address_entity_varchar` while the column stayed empty — with
no error anywhere.

## Four pieces, and why each is load-bearing

The value crosses four boundaries, and it disappears **silently** at a
different one if any piece is missing:

| Piece | Without it |
|---|---|
| `used_in_forms` in the data patch | field never renders |
| `Plugin\ScopeAdditionalPhoneToCustomAttributes` | browser drops it before the request — `new-customer-address.js` maps a hardcoded field list, and `custom_attributes` is its only extension point |
| `Plugin\AllowAdditionalPhoneOnQuoteAddress` | `Quote\Address::setCustomAttribute()` rejects it — core's `CustomAttributeList` is a hardcoded `return []` |
| `Observer\FlattenAdditionalPhone` | accepted but never written — `quote_address` is a flat table and `setCustomAttribute` only touches `_data['custom_attributes']` |

None of the four produces an error when absent. That is why they are documented
together here as well as on each class.

The **customer** address needs no equivalent of the observer: it is a real EAV
entity and `AddressRepository` already flattens custom attributes on save.

## Travelling with the order

`etc/fieldset.xml` mirrors core's declarations for `telephone` exactly — saved
address → quote, quote → order, order → address book, and order → quote on
reorder. Anything less gives a field that survives some journeys and vanishes on
others, which is worse than not having it.

## The second job: `city` is derived from the governorate

Figma's form (`557:5173`) collects a governorate and no city. Magento's
`Address\Validator\General` **requires** a city, so hiding the field would give
a form that looks right and cannot be submitted.

`Model\GovernorateCity` fills `city` from the chosen governorate, driven by
`Observer\FillCityFromGovernorate` on `customer_address_save_before` and
`sales_quote_address_save_before` — every door an address is written through,
not just the checkout's. In Egypt the governorate *is* the city-level division
for addressing, so the result is a correct address rather than a placeholder
that satisfies a validator.

### It re-derives on edit, and that is the whole subtlety

The first version stopped at "city is not empty, leave it alone", which broke on
the ordinary path of **editing** an address: change the governorate from
المنوفية to القاهرة and the region moved while the city did not, so the row held
two different governorates at once and the checkout's address card printed both
on one line.

A derived city is now told apart from a real one by comparing it to the
governorate the address had **before** this save (`getOrigData('region_id')`):

| city | what happens |
|---|---|
| empty | filled from the governorate |
| the current governorate's name | already correct, left alone |
| the *previous* governorate's name | re-derived — it was ours |
| anything else | somebody chose it; untouched |

This is why it must stay a `*_save_before` observer: after the save there is
nothing left to compare against.

## `رقم اضافي` is optional, and validated anyway

`Model\Validator\AdditionalPhone` is registered on Magento's own
`Customer\Model\Address\CompositeValidator` (`etc/di.xml`). An empty value passes;
a value that is not a reachable Egyptian mobile number is rejected with a message
that names the field and gives an example.

**It had no validation at all before, on either side.** Measured on the deployed
store: the input carried no rule, the account dialog's form fragment emitted no
`data-mage-init` (so its `required` markers were inert too), and on the server
`Metadata\Form\Text::validateLength()` only applies a length rule when an
`input_validation` rule is also present — which the attribute has none of. The
column took any 32 characters: a name, an email, half a sentence.

**The rule is Spartrak_CustomerAuth's `Phone\Normalizer`, not a regex of our
own.** That class is the store's single definition of a usable mobile number — it
guards sign-up, sign-in and the phone-change OTP, where the number *is* the
account identity. It folds Arabic-Indic and Persian digits, understands `01…`,
`+20…` and `0020…`, and requires a carrier prefix that can receive an SMS.
Writing a second answer here would be two answers to one question, and they
would drift the first time a prefix is added.

That is also why the client side has **no** format rule: a hand-written regex in
a `data-validate` attribute could not do the digit folding without becoming
unreadable, and one that got it wrong would reject the Arabic keyboard this
store's primary locale is typed on. The theme's form now initialises Magento's
validation widget, so `required` works inline; the format is checked once, on the
server, by the class that already knows the answer.

**One registration covers every door,** because `AbstractAddress::validate()` is
inherited by both address models: `AddressRepository::save()` validates a
customer address before saving it (the account dialog, the checkout's
`Spartrak\Checkout\Controller\Address\Save`, the admin customer form, the REST
API), and `QuoteValidator::validateBeforeSubmit()` validates the quote's shipping
and billing addresses at place-order. A repository plugin would have missed the
quote; a `*_save_before` observer fires after the repository has already
validated — the sequencing trap recorded on `Plugin\FillCityOnAddressRepositorySave`.
