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
