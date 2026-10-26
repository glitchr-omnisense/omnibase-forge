# Licensing a game or an application

How a licensed piece of software - a Unity game, a desktop application such
as Switch for Coder - checks that it may run, machine by machine.

## The model

- A **licence** (`License`) covers one software for so long (`expiresAt`,
  null: perpetual) and its releases until a date (`updatesUntil`).
- It has **seats** (`seats`), an e-mail each (`LicenseSeat`): the buyer's own
  first, the others given from their account or from the back office. Each
  seat has **its own key** (`glk_…`), made from the account page.
- Each seat may **activate** the software on `machinesPerSeat` machines (2 by
  default, set on the offer). Its holder frees a machine from their account
  ("Mes licences"); the studio from the back office (Machines activées).

## The API

JSON over HTTPS, no session: the seat's key is the credential.

### `POST /api/licenses/activate`

At the first start, and then whenever the token is about to run out.

```json
{
  "key": "glk_XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX",
  "software": "switch-for-coder",
  "machine": "<a stable identifier of this machine>",
  "name": "MacBook Air de Camille",
  "platform": "macos",
  "version": "1.4.0"
}
```

- `software`: the software's slug on the site - a key opens only its own.
- `machine`: stable across restarts and updates. Unity:
  `SystemInfo.deviceUniqueIdentifier`; macOS: the `IOPlatformUUID`; Windows:
  `MachineGuid`. The site stores only its SHA-256.
- `name`, `platform`, `version`: shown to the holder, to recognise the machine.

Answers:

| Status | Body | What to do |
|---|---|---|
| 200 | `{"status": "active", "token": "…", "license": {…}, "machines_left": 1}` | Store the token; run. |
| 401 | `{"error": "invalid_key"}` | Ask for the key again. |
| 403 | `{"error": "wrong_software"}` / `{"error": "license_not_valid", "status": "revoked"}` | Stop; say why. |
| 409 | `{"error": "no_machine_left", "machines": [{"name", "platform", "last_seen"}], "manage_url": "…"}` | Show the machines, open `manage_url` to free one. |
| 422 | the validation errors | A bug in the request. |
| 503 | `{"error": "not_configured"}` | The site has no signing key yet. |

Activating an already active machine takes no new place: call it as often
as needed.

### `POST /api/licenses/release`

`{"key": "…", "machine": "…"}` - the machine frees its own place (an
uninstall, a "deactivate" menu). `{"status": "released"}` or
`{"status": "not_activated"}`.

### `GET /api/licenses/public-key`

`{"algorithm": "Ed25519", "public_key": "<base64>"}` - ship it in the
software rather than fetching it: a key fetched at run time could be swapped.

## The token, checked offline

```
token = base64url(payload JSON) + "." + base64url(Ed25519 signature of the payload JSON)
```

The software runs when, all of them:

1. the signature verifies with the public key it ships with;
2. `software` is its own slug;
3. `machine` equals the SHA-256 (hex) of its own machine identifier;
4. now is before `valid_until` (UNIX time) - past it, activate again, online;
5. for a version gate: its release date is before `updates_until`, when set.

`valid_until` is `FORGE_LICENSE_GRACE_DAYS` (30 by default) after the last
activation, never beyond the licence's expiry: a machine offline for longer
must come back online once; a revoked licence or a freed machine stops at
the latest then.

### Unity (C#)

The `com.glitchr.licensing` package (served by the forge's Unity registry,
see [upm.md](upm.md); source: github.com/glitchr-studio/unity-licensing)
does all of it - `LicenseClient.ActivateAsync()`, `LicenseClient.Check()` -,
with Ed25519 in managed code. By hand, with an Ed25519 library:

```csharp
string[] parts = token.Split('.');
byte[] json = Base64Url.Decode(parts[0]);
byte[] signature = Base64Url.Decode(parts[1]);
bool genuine = Ed25519.Verify(signature, json, PublicKey);
var payload = JsonUtility.FromJson<LicensePayload>(Encoding.UTF8.GetString(json));
string machine = Sha256Hex(SystemInfo.deviceUniqueIdentifier);
bool runs = genuine && payload.software == "my-game" && payload.machine == machine
    && DateTimeOffset.UtcNow.ToUnixTimeSeconds() < payload.valid_until;
```

### Swift (macOS)

CryptoKit verifies Ed25519 natively:

```swift
let parts = token.split(separator: ".").map(String.init)
let json = Data(base64URLEncoded: parts[0])!, signature = Data(base64URLEncoded: parts[1])!
let key = try Curve25519.Signing.PublicKey(rawRepresentation: Data(base64Encoded: publicKey)!)
let genuine = key.isValidSignature(signature, for: json)
let payload = try JSONDecoder().decode(LicensePayload.self, from: json)
let machine = SHA256.hash(data: Data(platformUUID.utf8)).map { String(format: "%02x", $0) }.joined()
let runs = genuine && payload.software == "switch-for-coder" && payload.machine == machine
    && Date().timeIntervalSince1970 < payload.valid_until
```

## The signing key

`bin/console forge:license:keypair` makes a pair. The secret goes in the
vault (`bin/console secrets:set FORGE_LICENSE_SIGNING_KEY`), the public key
in the software. A new pair makes every token issued so far unverifiable:
ship the new public key first.

## Signed download links

An artifact is downloaded through a link signed by omniscient/omnibase's
`Base\Service\DownloadLinks` (route `forge_download_file`, valid
`forge.download_ttl` seconds; the Composer index signs its links for thirty
days). Who may download was checked when the link was handed out (the licence);
the download action only checks the signature. The forge no longer has a
DownloadLinks of its own.
