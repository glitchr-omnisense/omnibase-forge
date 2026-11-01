# Changelog

## [Unreleased]

### Changed
- `Software::__toLink()` link through `Base\Contract\Link::to()` (the Urls contract; the same address under
  Symfony, in the core 3.x and 4.x) instead of BaseTrait's `getRouter()->generate()`, which the
  core deprecates since BaseTrait names contracts only (omnisense/omnibase 4.x, step F2f).

### Fixed
- README: the package's name, omnibase/forge (the title was base-bundle-forge), omnisense/omnibase
  and omnibase/marketplace on GitHub in place of base-bundle and base-bundle-market on the
  abandoned gitlab.glitchr.dev, git/git-bundle's home, omnisense/omnistate (not glitchr/omnistate),
  and the core's requirement as composer.json states it (3.0 or 4.0).
