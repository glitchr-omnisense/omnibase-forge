---
title: "The hub"
order: 35
---

# The hub

The forge serves, beside its Unity registry ([upm.md](upm.md)), what the hub
clients read: the Unity window `com.glitchr.hub` (Window > Glitch Art Hub, in the
editor) and the desktop hub. A client is pointed at one URL, the **hub
manifest**, and finds there the brand, the sources and whether something can
be bought.

## The manifest: `GET /upm/-/hub.json`

```json
{
  "version": 1,
  "name": "Glitch Art",
  "logo": "https://glitchr.io/assets/images/brand/monkey.png",
  "color": "#fc6875",
  "sources": [
    {
      "name": "Glitch Art",
      "type": "upm",
      "url": "https://glitchr.io/upm",
      "scopes": ["com.glitchr"],
      "catalog": "https://glitchr.io/upm/-/hub/catalog.json"
    }
  ],
  "games": "https://glitchr.io/upm/-/hub/catalog.json",
  "checkout": {
    "device": "https://glitchr.io/upm/-/hub/device",
    "token": "https://glitchr.io/upm/-/hub/token",
    "quote": "https://glitchr.io/upm/-/hub/quote",
    "purchases": "https://glitchr.io/upm/-/hub/purchases",
    "methods": "https://glitchr.io/upm/-/hub/payment-methods"
  },
  "licenses": {
    "list": "https://glitchr.io/upm/-/hub/licenses",
    "activate": "https://glitchr.io/api/licenses/activate",
    "release": "https://glitchr.io/api/licenses/release"
  },
  "licensing": {
    "algorithm": "Ed25519",
    "publicKey": "<base64, 32 bytes>",
    "registry": "https://glitchr.io/upm",
    "previousKey": "<base64>",
    "rotation": "<base64, 64 bytes>",
    "previousKeyValidUntil": 1795564800
  }
}
```

- `sources`: UPM registries (`type: upm`, `url`, `scopes`, an optional
  `catalog`) or Git packages (`type: git`, `url`). A client adds them to a
  project's `Packages/manifest.json` as scoped registries or Git
  dependencies.
- `checkout`: present when the hub sells. A client shows "Buy" only for a
  source whose manifest has it - a static hub (a copied template on GitHub
  Pages) never does. `quote`, `purchases` and `methods` are there when the
  forge takes one-click purchases (`forge.hub.payment_gateway`, below).
- `licensing`: present once the forge has a signing key
  (`bin/console forge:license:keypair`). Data only, never code:
  - `publicKey`: the Ed25519 key the licence tokens are signed with. Forge
    Store pins it when the store is added (trust on first use) and checks
    licences against the pinned key, not against what the manifest serves
    later; a different key waits for its user's consent.
  - `registry`: the registry the tokens are bound to - each token's
    `registry` (see [licensing.md](licensing.md)). A token whose `registry`
    is another store's never opens this store's packages.
  - after a rotation (`bin/console forge:license:rotate`): `previousKey`, and
    `rotation`, the previous key's Ed25519 signature of
    `"forge-key-rotation:v1:" + publicKey` - a client that pinned
    `previousKey` moves to `publicKey` without asking; and
    `previousKeyValidUntil` (UNIX time), past which no token the previous key
    signed may run. Published only when the signature verifies; only the last
    rotation is kept, so a client pinned two keys back asks its user.

It is configured in `config/packages/forge.yaml`:

```yaml
forge:
    hub:
        name: 'Glitch Art'
        logo: '/assets/images/brand/monkey.png'   # or an absolute URL
        color: '#fc6875'                           # #rrggbb, checked at boot
        scopes: ['com.glitchr']                    # empty: those of the Unity packages
        checkout: true
        payment_gateway: stripe                    # the omnitrade gateway one-click purchases go through; null: none
```

## The catalogue: `GET /upm/-/hub/catalog.json`

What a shop window shows and the Package Manager's search does not:

- `packages`: every published Unity package, the paid ones too, each with
  its versions and their changelog, its picture and screenshots, its page,
  `pricing` (`free` or `licensed`), its `offers` (id, title, price in cents,
  currency, seats, months) and `installable` - true when the caller's
  licence key (`Authorization: Bearer`, as for the registry) covers it;
- `games`: every published game, with `play` (the address of its WebGL
  build) only when it is played in the browser - a free game, or a paid one
  whose playable preview is open ([games.md](games.md)) -, its platforms and
  its offers.

## Buying: the device flow

The editor has no session on the site. It is linked to its user's licences
the way a television is linked to an account (OAuth's device flow, RFC 8628):

1. `POST /upm/-/hub/device` `{"item": "com.glitchr.licensing"}` answers a
   `device_code` (kept by the editor), a `user_code` (`ABCD-EFGH`, shown to
   the user) and `verification_uri_complete`, which the editor opens in the
   browser.
2. On that page (`/hub/link`), signed in, the user sees the code and the
   item's offers; they buy one through the shop's cart and checkout, in a tab
   of their own, then come back and press "Link this editor".
3. Meanwhile the editor asks `POST /upm/-/hub/token` `{"device_code": ...}`
   every `interval` seconds: `authorization_pending`, `slow_down` or
   `expired_token` (400) until the user links it; then, once, `{"account_token",
   "email", "registry"}` and, when the user holds a licence, `"key"`.

The key is a new key of one of the user's seats (the seat's previous key
stops working); it opens every licence of that e-mail, as for Composer. The
editor stores it as the registry's token in the user's `~/.upmconfig.toml`,
never in the project. The codes live ten minutes, in the cache only.

The account's token (`gla_` and 32 characters, like a key) is given even to a
user without a licence: it is what the editor buys with and manages the saved
payment methods with (below), as `Authorization: Bearer gla_...`. The client
keeps it in the user's folder (the OS keychain), never in a project; only its
SHA-256 is stored here (`Entity\HubToken`). It opens no package: the registry
still takes the licence key.

## Licences: `GET /upm/-/hub/licenses`

With the key as a Bearer: the holder's e-mail and each valid licence - its
software (`slug`, which the licence API asks for, its name, its UPM name, its
page), status, expiry, updates, seats, machines per seat, and the machines
of that holder's seat, each with the SHA-256 of its identifier (`machine`),
which a client compares with its own to say "this machine". A client
activates and releases itself through the addresses the manifest lists
under `licenses` (`/api/licenses/activate`, `/api/licenses/release`, see
[licensing.md](licensing.md)).

## Buying in one click

From Forge Store or Forge Hub, once the editor is linked (the account's token
above) and a payment method saved - plan items 16.2 to 16.4, and 17.3's
confirmation. The forge goes through omnisense/omnitrade 1.1 and the
gateway `forge.hub.payment_gateway` names (Stripe: omnitrade/stripe 1.1); the
shop needs a payment method whose `gatewayFactory` is that gateway. Every
answer is JSON, `Cache-Control: no-store`; without the account's token, 401
with `WWW-Authenticate: Bearer realm="Forge account"`.

**The forge never sees a card number.** A method is saved on the provider's own
page; the forge keeps only who the user is there (`Entity\PaymentCustomer`:
Stripe's `cus_...`) and reads the methods from the provider each time.

### Payment methods

| Request | Answer |
|---|---|
| `GET /upm/-/hub/payment-methods` | `{"gateway": "stripe", "methods": [{"id": "pm_...", "type": "card", "label": "Visa •••• 4242", "brand": "visa", "last4": "4242", "expires": "2030-12", "expired": false}]}` - none before one is saved |
| `POST /upm/-/hub/payment-methods` | 201 `{"id": "cs_...", "url": "https://checkout.stripe.com/..."}`: the provider's page to open - Forge Hub's webview, or the system browser from the editor. It sends the user back to `/hub/payment-methods/saved` ("go back to Unity"); the client then lists the methods again |
| `DELETE /upm/-/hub/payment-methods/{id}` | 204; 404 `unknown_payment_method` for a method that is not the user's (nothing is removed) |

Changing a method is adding the new one and removing the old one.

### The confirmation, then the purchase

1. `GET /upm/-/hub/quote?offer=<id>` (the offer's `id` in the catalogue):
   `{"offer", "title", "software", "package", "store", "price", "currency",
   "method"}` - `price` is what the order charges this user (minor units, VAT
   and promotions included: it may differ from the catalogue's `price`, which
   is before VAT), `store` the manifest's `name`, `method` the saved method it
   would be charged to (null: none saved). The client shows price, currency
   and store name; one click opens the confirmation, one confirms it.
2. `POST /upm/-/hub/purchases` with `{"offer": <id>, "price": <the quote's
   price>, "currency": "EUR", "method": "pm_..."}` (`method` optional: the
   first one that has not expired) and an `Idempotency-Key` header (8 to 64 of
   `A-Za-z0-9_.:-`, one per confirmation, kept and sent again on a retry):

| Status | Body | Meaning |
|---|---|---|
| 201 | `{"id", "status": "paid", "order", "price", "currency", "software", "package", "key", "email", "registry"}` | charged; the licence is issued and its key handed over (once) |
| 201 | `{"id", "status": "requires_action", "url", ...}` | the bank asks its buyer (3-D Secure): open `url`; the bank sends them back to `/hub/purchases/<id>/done` |
| 201 | `{"id", "status": "pending", ...}` | the provider has not said yet: poll |
| 200 | the same purchase, header `Idempotent-Replayed: true` | the same key again: nothing charged again |
| 402 | `{"error": "payment_refused", "status": "refused", "reason", ...}` | the card was refused; nothing charged |
| 409 | `{"error": "price_changed", "offer", "price", "currency"}` | the amount is no longer the one confirmed: show the new one, confirm again (new key) |
| 409 | `{"error": "payment_method_required"}` | no saved method: save one first |
| 409 | `{"error": "purchase_in_progress"}` | another purchase of this user is still running (15 s): retry with the same key |
| 422 | `{"error": "unknown_payment_method"}`, `{"error": "idempotency_key_reused"}`, `{"error": "invalid_request", "violations"}` | a method that is not the user's; the key sent before with another offer, price or currency; a body that does not read |
| 400 | `{"error": "idempotency_key_required"}` | no key, or not one |
| 404 | `{"error": "unknown_offer"}` | not an offer for sale |
| 503 | `{"error": "payments_unavailable"}` | no gateway, no omnitrade 1.1, the provider unreachable |

3. `GET /upm/-/hub/purchases/<id>` while it waits (every few seconds): the
   same body; the forge asks the provider meanwhile, as the shop's return page
   does. Once `paid`, the first answer carries `key`, `email` and `registry`
   (the key of the user's seat on the new licence; then it is spent, as in the
   device flow): the client writes it into `~/.upmconfig.toml`, installs
   `package` from the registry, and activates through `licenses.activate` - a
   v2 token bound to `registry` and `package` ([licensing.md](licensing.md)).

**Never charged twice.** A purchase is one row per user and idempotency key
(`Entity\HubPurchase`), made under a lock of the user's (symfony/lock): a
retry, a double click or a second process finds it and charges nothing more.
The order it pays is one of the shop's (`Checkout::settle()`, its row locked,
paid once), and the provider is sent an idempotency key of its own.

**Paid by the webhook.** After the bank's check, Stripe's
`payment_intent.succeeded` reaches the shop's webhook
(`/marketplace/<gateway>/webhook`): the order is confirmed and the licence
issued (`OrderPaidSubscriber`) in the same transaction. Subscribe the endpoint
to `payment_intent.succeeded` and `payment_intent.payment_failed` as well as
the Checkout events (omnitrade/stripe 1.1's `RegisterWebhook` does by
default). A `payment_failed` that only means "ask the buyer" (3-D Secure) is
not a refusal; a real one refuses the purchase, and its order is abandoned
rather than left as the user's cart.

