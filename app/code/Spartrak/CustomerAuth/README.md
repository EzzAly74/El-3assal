# Spartrak_CustomerAuth

Phone-number identity and OTP verification for the SpareTrak storefront.
Module 1 in [`11-MODULE-ARCHITECTURE.md`](../../../../.claude/docs/11-MODULE-ARCHITECTURE.md).

## Auth model

Decided 2026-08-24 with the project owner, from the flow recorded in
[`01-FIGMA-AUDIT.md`](../../../../.claude/docs/01-FIGMA-AUDIT.md) (login screen shows
phone + country code **and** a password field; a separate OTP step exists):

| Journey | Credential | SMS sent? |
|---|---|---|
| Returning shopper signs in | phone + password | no |
| New shopper registers | phone → OTP → name + password | yes, once |
| Forgotten password | phone → OTP → new password | yes, once |

OTP is **not** a second factor on every sign-in. That was considered and rejected:
it bills an SMS per login and adds a wait for the farmers and mechanics this
storefront is built for. If the client later wants login 2FA, `Otp\Service` already
supports it — add a `Purpose` constant and a step to the modal.

## What this module does NOT reimplement

Credential checking, password strength policy, password history, failed-attempt
lockout, session eviction on password change, the `customer_login` event (and
therefore guest-cart merge), and welcome-email dispatch all stay in
`Magento_Customer`. This module adds exactly three things Magento has no concept of:

1. a phone number as the account's unique identifier,
2. an OTP lifecycle with rate limiting and an SMS boundary,
3. a synthesized email for shoppers who never supply one.

## HTTP API

All endpoints are `POST`, return JSON, and **require `form_key`**. They deliberately
do not implement `CsrfAwareActionInterface` — that interface is how a controller opts
*out* of form-key validation, and these are the last endpoints that should.

Base route: `/phone-auth/`

| Endpoint | Body | Success payload |
|---|---|---|
| `otp/send` | `phone`, `purpose` | `expires_in`, `resend_in`, `code_length`, `real_delivery` |
| `otp/verify` | `phone`, `purpose`, `code` | `verification_token`, `next_step` |
| `account/createPost` | `phone`, `verification_token`, `name`, `password`, `password_confirmation`, `email?` | `customer_name`, `form_key` |
| `account/loginPost` | `phone`, `password` | `customer_name`, `form_key` |
| `password/resetPost` | `phone`, `verification_token`, `password`, `password_confirmation` | `customer_name`, `form_key` |

`purpose` is `signup` or `password_reset`.

Every response has `success: bool`. Failures add `message` (already translated and
safe to render) and sometimes `retry_after` (429) or `attempts_remaining` (403).

### Two things the frontend must do

**Use the returned `form_key`.** Sign-in, registration and password reset all
regenerate the session id, which invalidates the cached form key. Keep POSTing the
old one and the next request fails an "Invalid Form Key" check. Every auth response
returns the fresh key for this reason.

**Let section invalidation happen.** `etc/frontend/sections.xml` registers these
actions so Magento refetches customer section data. Without it the header keeps
rendering the logged-out state until a hard reload — the session is real, the UI
just never hears about it.

### `real_delivery`

`false` means the configured gateway is the log driver and no SMS was sent. Use it
to show "SMS is not configured on this environment" in staging. It is never `false`
in production with a real gateway configured.

## Adding an SMS provider

1. Implement `Api\SmsGatewayInterface` (four methods).
2. Add one `<item>` to the `gateways` argument of `Model\Sms\GatewayResolver` in `etc/di.xml`,
   keyed by the class's `getCode()`.
3. Select it in **Stores → Configuration → Customers → Phone Authentication → SMS Delivery**.

The admin dropdown is generated from that same pool, so the config options and the
resolver cannot drift apart. Implementations must not retry internally — the retry
mechanism is the shopper's own rate-limited Resend button, and a retry loop on the
request thread turns a slow provider into a page timeout.

The shipped default is the **log driver**, which writes to `var/log/spartrak_otp.log`
and delivers nothing. That is deliberate: a silent no-op gateway makes staging look
healthy while every shopper is locked out.

## Security properties

- OTP codes are stored only as a salted one-way hash (`EncryptorInterface`). A
  database dump cannot recover a live code.
- Issuing a code revokes every older live code for that number+purpose, so exactly
  one code is valid at a time.
- Failed attempts are counted with a single atomic `UPDATE`, so concurrent guesses
  cannot each read a stale count and win an extra try.
- Verification returns a single-use proof token bound to the phone **and** the
  purpose, not a session. A signup token cannot be spent on a password reset.
- Three independent send limits: per-phone cooldown, per-phone quota, per-IP quota.
  All count rows in the ledger rather than a cache counter — cache counters are
  per-node, evaporate on a flush, and are free for an attacker to reset.
- `password_reset` on an unregistered number returns a success-shaped response and
  sends nothing, so the endpoint cannot be used to test whether a number is a
  customer. `signup` *does* disclose an existing account, because the shopper needs
  that answer; rate limiting is what keeps it from being an enumeration API.
- Sign-in reports one message for "no such number" and "wrong password" alike.
- Gateway failure revokes the row it just created, so a shopper never holds a
  cooldown for a code that was never sent.
- Phone numbers are masked in logs (`+2010****5678`).

### Deployment check that is easy to miss

The per-IP quota reads `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress`. Behind
a CDN or load balancer whose proxy headers are **not** configured as trusted, every
request appears to come from the balancer and the per-IP quota throttles the whole
store as one client. Confirm the trusted-proxy configuration before relying on it.
The per-phone limits are unaffected either way, which is why they carry the primary
defence.

## Schema

`spartrak_customer_otp` — the OTP ledger. Also the rate-limit substrate. A daily cron
(`spartrak_customer_auth_purge_otp`) deletes spent rows after
`spartrak_auth/otp/purge_after_days`; live rows are never deleted regardless of age,
so the job cannot break an in-flight registration. This is data retention, not
housekeeping: every row holds a phone number.

`customer_entity` gains `phone_number` (UNIQUE) and `phone_verified_at`. These are
real columns, not EAV rows, for two reasons: a UNIQUE index is the only way to make
"one account per phone" atomic, and sign-in resolves phone → customer on every
attempt. `Setup/Patch/Data/AddPhoneAttributes` declares the matching EAV attributes
with `backend_type = static` so the admin form and grid still see them.

**Scope limit, deliberate:** the unique index has no website column, so a phone
identifies one account store-wide. Correct for a single Egyptian website. If a second
website is added *and* customer accounts are scoped per-website, revisit this before
launch.

`phone_number` is exposed on `adminhtml_customer` only — **not** on
`customer_account_edit`. Letting a shopper edit their login identifier from a normal
form would allow changing it to an unverified number, or to someone else's, with no
proof. A "change my number" journey needs its own OTP flow through `Otp\Service`.

## Synthesized emails

Magento requires a unique, format-valid email on every customer; the designed
registration step collects a name and a password. When no email is given, one is
synthesized as `phone-<digits>@<placeholder_email_domain>`, defaulting to
`phone.sparetrak.invalid`.

**Keep that domain under `.invalid`** (RFC 2606 reserves it, so nobody can ever
register it). A placeholder on a domain someone else owns would send real order mail
to a stranger. `Plugin\Customer\SuppressPlaceholderWelcomeEmail` stops Magento
attempting delivery to these addresses at all — repeated hard failures degrade the
sending domain's reputation, which starts hurting the mail that *does* matter.

Recognition is by domain, so treat `placeholder_email_domain` as write-once:
changing it stops the module recognizing previously created placeholders.

## Configuration

**Stores → Configuration → Customers → Phone Authentication**

Defaults: 6-digit code, 5-minute lifetime, 5 verify attempts, 60-second resend
cooldown, 5 sends per phone and 20 per IP per hour, 15-minute proof token, 7-day
retention.

Code length defaults to 6 rather than the 4 boxes the Figma OTP component draws. A
4-digit space with a 5-attempt cap gives an attacker roughly 1-in-2,000 per issued
code, and codes can be re-requested. **If the 4-box design is mandatory, set
`code_length` to 4 and `max_verify_attempts` to 3 together** — the two settings have
to be chosen as a pair. The frontend renders exactly `code_length` boxes, so the
component follows configuration rather than hardcoding a count.

Every security-relevant value is clamped in `Model\Config` as well as validated in
`system.xml`, because `bin/magento config:set`, a `config.php` deployment or a direct
`core_config_data` write all bypass admin-form validation.

## Tests

`Test/Unit/Model/Phone/NormalizerTest.php` covers the normalizer, which is the
highest-risk pure logic here: everything downstream assumes one number maps to one
canonical string, and if two spellings normalize differently a shopper silently gets
two accounts and the rate limit is bypassed by adding a space. Cases include all four
Egyptian carrier prefixes, Arabic-Indic and Persian digit input, landline rejection,
idempotence, and log masking.

`vendor/` is not present in this repository, so run it from a full install:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Spartrak/CustomerAuth/Test/Unit
```

## Install

```bash
bin/magento module:enable Spartrak_CustomerAuth && bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

`setup:di:compile` is required, not optional — the `gateways` di.xml argument and the
generated `OtpRequestFactory` are both compile-time artifacts.
