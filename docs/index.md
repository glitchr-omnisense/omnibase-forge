---
title: omnibase/forge
order: 1
---

# omnibase/forge

omnibase/forge is a software forge for an omnibase site, sold through
omnibase/marketplace: what a studio sells besides its time. Its namespace is
`Base\Forge\`.

## What it adds

- **Software and releases.** Each git tag of a repository becomes a release;
  git/git-bundle reads the tree in-process and the release is zipped from it.
- **Downloads.** Free software for anyone; licensed software against a licence
  bought as a marketplace product (`LicenseOffer`). Files are served through
  signed, short-lived addresses and nginx's `X-Accel-Redirect`.
- **A private Composer repository.** `/composer/packages.json` lists the free
  packages for anyone and the licensed ones for the holder of a valid
  licence, authenticated with HTTP Basic (the e-mail and the licence key).
- **A Unity registry.** `/upm` speaks the npm registry protocol Unity's
  Package Manager reads from a scoped registry ([The Unity registry](upm.md)).
- **The hub.** A manifest, a catalogue with the paid packages' offers and the
  games, and a device flow that links a Unity editor to its user's licences
  ([The hub](hub.md)).
- **Games and applications.** Builds per platform attached to each release, a
  WebGL build played in the game's page ([Games and applications](games.md),
  [Licensing](licensing.md)).
- **Client projects.** A board the client and the studio both move cards on
  ([The project board](board.md)), the time logged, the repository's history,
  the pipelines ([Pipelines](pipelines.md)).
- **Support hours.** Prepaid packs (`HourPack`) credit a ledger, logged time
  debits it; the balance is the sum of the ledger's lines.
- **Quotes.** Asked from the site, priced and sent from the back office,
  accepted and paid like any order, their hours credited ([Quotes](quotes.md)).

## Pages

- [Installation](installation.md)
- [Games and applications](games.md)
- [Licensing a game or an application](licensing.md)
- [The project board](board.md)
- [Pipelines](pipelines.md)
- [The hub](hub.md)
- [The Unity registry](upm.md)
- [Quotes](quotes.md)
- [Configuration reference](configuration.md)
- [Reference](reference.md)
- [Testing](testing.md)
