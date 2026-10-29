---
title: "Configuration reference"
order: 40
---

# Configuration reference

## The `forge` tree

Dumped with Symfony's `YamlReferenceDumper` from
`Base\Forge\DependencyInjection\ForgeConfiguration`:

```yaml
forge:

    # Where release archives are written. Never under public/: they are handed out by the download controller.
    storage_dir:          '%kernel.project_dir%/var/storage/forge'

    # Where the repositories of projects and software that are not in git.repositories are cloned (<name>.git).
    repositories_dir:     '%kernel.project_dir%/var/storage/repositories'

    # The nginx `internal` location aliasing storage_dir (X-Accel-Redirect). Empty: PHP streams the file itself.
    accel_prefix:         /_protected/forge/

    # How long a signed download link stays valid, in seconds.
    download_ttl:         600

    # The market store (slug) hour packs and accepted quotes are sold from.
    support_store:        support

    # The market store (slug) licence offers are sold from.
    software_store:       logiciels

    # Default hourly rate of a quote line, in cents.
    hourly_rate:          6000

    # Below this balance a client is reminded to top up (forge:hours:low-balance).
    low_balance_minutes:  120

    # Where quote requests are mailed. Null: they are only listed in the back office.
    contact_email:        null

    # The hub manifest (/upm/-/hub.json) the Unity window (com.glitchr.hub) and the desktop hub read: the brand and the sources. See docs/hub.md.
    hub:

        # The hub's name, shown by the clients.
        name:                 Forge

        # Its logo: an absolute URL, or a path on this site.
        logo:                 null

        # Its accent colour, #rrggbb.
        color:                null

        # The scopes of the Unity registry (com.glitchr): those of the Unity packages when empty.
        scopes:               []

        # Whether the clients offer to buy: the licensed packages' offers, bought on this site and linked to the editor by a device code.
        checkout:             true
```

| Key | Default | Read by |
|---|---|---|
| `storage_dir` | `var/storage/forge` | the release archives and builds; never under `public/` |
| `repositories_dir` | `var/storage/repositories` | the clones of the repositories not in `git.repositories` (`<name>.git`) |
| `accel_prefix` | `/_protected/forge/` | the download controller's `X-Accel-Redirect`; empty, PHP streams the file |
| `download_ttl` | 600 | how long a signed download link stays valid, in seconds |
| `support_store` | `support` | the marketplace store hour packs and accepted quotes are sold from |
| `software_store` | `logiciels` | the store licence offers are sold from |
| `hourly_rate` | 6000 | a quote line's default rate, in cents |
| `low_balance_minutes` | 120 | `forge:hours:low-balance`: the balance under which a client is reminded |
| `contact_email` | null | where quote requests are mailed; null, the back office only |
| `hub.name`, `logo`, `color`, `scopes`, `checkout` | `Forge`, none, none, the Unity packages', true | the hub manifest ([The hub](hub.md)) |

Each key is a container parameter (`%forge.storage_dir%`,
`%forge.hub.name%`...).
