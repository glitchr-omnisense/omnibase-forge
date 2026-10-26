# The Unity registry

The forge serves its Unity packages to Unity's Package Manager as a
**scoped registry**: the npm registry protocol, under `/upm`, beside the
Composer repository (`/composer/packages.json`) it mirrors. A piece of
software is a Unity package when it has a **UPM name** (`Software::$upmName`,
`com.glitchr.<name>`) and a git repository whose tree at each tag is the
package: `package.json` at its root, every asset with its `.meta`.

## What it serves

| Request | Answer |
|---|---|
| `GET /upm/-/v1/search?text=<scope>&from=<n>&size=<n>` | The packages whose name, display name, description or keywords hold every word of `text`, one page. |
| `GET /upm/-/all` | Every package, the older listing. |
| `GET /upm/<name>` | The package document: each version the caller may install, its `package.json` at that tag, `dist.tarball`, `dist.shasum` (sha1), `dist.integrity` (sha512). |
| `GET /upm/<name>/-/<name>-<version>.tgz` | The tarball. |

This is what Unity 6 (6000.0) was seen asking, on 2026-10-26, from a probe
registry: the search, page after page until one comes back empty (`/-/all`
when the search is a 404), then the package document, then `HEAD` and
`GET` of the tarball - every request with the registry's token from
`.upmconfig.toml` as `Authorization: Bearer <token>`.

## The tarball

`ArtifactBuilder::buildUpm()` writes it from the tree at the tag, as npm
does: a gzip'd ustar archive, every file under `package/`, its
`package.json` carrying the package's UPM name and the release's version
(whatever the tag's file said). Its sha256, sha1 and sha512 are stored on the
artifact (format `upm`). `forge:release:sync` builds it with the zip for each
new tag; "Build" in the back office rebuilds both.

## Free and licensed packages

A free package is listed and installed by anyone. A licensed one only by the
holder of a valid licence covering that version (`License::covers()`), as
for Composer: the licence key goes in the user's `~/.upmconfig.toml`
(`%USERPROFILE%\.upmconfig.toml` on Windows):

```toml
[npmAuth."https://glitchr.io/upm"]
token = "glk_XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
alwaysAuth = true
```

The key alone is enough: it names its seat, whose e-mail names every licence
it holds. `_auth = "<base64 of e-mail:key>"` (Basic) works too, the e-mail
then checked against the seat's.

| Caller | Search | A licensed package's document and tarball |
|---|---|---|
| No key | free packages only | 401, `WWW-Authenticate: Bearer` |
| A wrong key | 401 | 401 |
| A key whose licences do not cover it | its covered packages | 403 |
| A key that covers it | listed | 200 |

Each tarball handed out is one `Download` row, channel `upm`, counted
against the licence that covered it (a `HEAD` is not counted).

## In the project

```json
"scopedRegistries": [
  {"name": "Glitch Art", "url": "https://glitchr.io/upm", "scopes": ["com.glitchr"]}
],
"dependencies": {
  "com.glitchr.licensing": "1.0.0"
}
```

The software page shows these lines for each Unity package.

## A development site

A forge run locally behind a self-signed certificate (`https://localhost:8453`)
is refused by the Package Manager until it trusts that certificate: give it
the certificate as an extra certificate authority, `caFile` in the
Package Manager's configuration file (Unity manual, "Configure your network
for Package Manager"):

```toml
caFile = "/path/to/localhost.crt"
```
