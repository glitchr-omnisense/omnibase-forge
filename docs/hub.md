# The hub

The forge serves, beside its Unity registry ([upm.md](upm.md)), what the hub
clients read: the Unity window `com.glitchr.hub` (Window > Hub, in the
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
    "token": "https://glitchr.io/upm/-/hub/token"
  }
}
```

- `sources`: UPM registries (`type: upm`, `url`, `scopes`, an optional
  `catalog`) or Git packages (`type: git`, `url`). A client adds them to a
  project's `Packages/manifest.json` as scoped registries or Git
  dependencies.
- `checkout`: present when the hub sells. A client shows "Buy" only for a
  source whose manifest has it - a static hub (a copied template on GitHub
  Pages) never does.

It is configured in `config/packages/forge.yaml`:

```yaml
forge:
    hub:
        name: 'Glitch Art'
        logo: '/assets/images/brand/monkey.png'   # or an absolute URL
        color: '#fc6875'
        scopes: ['com.glitchr']                    # empty: those of the Unity packages
        checkout: true
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
   `expired_token` (400) until the user links it; then, once, `{"key",
   "email", "registry"}`.

The key is a new key of one of the user's seats (the seat's previous key
stops working); it opens every licence of that e-mail, as for Composer. The
editor stores it as the registry's token in the user's `~/.upmconfig.toml`,
never in the project. The codes live ten minutes, in the cache only.
