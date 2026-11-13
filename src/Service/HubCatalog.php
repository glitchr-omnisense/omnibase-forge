<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\License;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Release;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Platform;
use Base\Forge\Repository\SoftwareRepository;
use Omnihub\Source;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the hub clients read - the Unity window (com.glitchr.hub) and the desktop hub: the hub
 * manifest (/upm/-/hub.json: the brand, the sources, whether and how to buy) and this forge's
 * catalogue (/upm/-/hub/catalog.json: its Unity packages, free and paid, with their versions,
 * changelogs, pictures and offers, and its games). The Unity registry itself (UpmRegistry) stays
 * what Unity's Package Manager reads; the catalogue adds what a shop window shows.
 *
 * Its games follow the shelf's rule: a free game is played in the browser and downloaded; a
 * paid one is downloaded once bought, played in the browser only when its playable preview is
 * open (Software::isPlayableInBrowser()).
 */
class HubCatalog
{
    public function __construct(
        private readonly SoftwareRepository $software,
        private readonly UpmRegistry $registry,
        private readonly UrlGeneratorInterface $urls,
        private readonly UrlHelper $urlHelper,
        #[Autowire('%forge.hub%')] private readonly array $hub = [],
        private readonly ?LicenseToken $tokens = null,
        private readonly ?HubPayments $payments = null,
    ) {
    }

    /** The hub manifest: version 1. A source without "checkout" offers nothing to buy. */
    public function manifest(): array
    {
        $registry = $this->registry->url();
        $scopes = $this->hub['scopes'] ?? [];
        if (!$scopes) {
            foreach ($this->software->findUpmPackages() as $software) {
                $scopes[] = implode('.', \array_slice(explode('.', (string) $software->getUpmName()), 0, 2));
            }
            $scopes = array_values(array_unique($scopes));
        }
        $logo = $this->hub['logo'] ?? null;

        return array_filter([
            'version' => 1,
            'name' => $this->hub['name'] ?? 'Forge',
            'logo' => $logo ? $this->urlHelper->getAbsoluteUrl($logo) : null,
            'color' => $this->hub['color'] ?? null,
            // Its own registry, written as omnisense/omnihub writes a source (the rest of the
            // manifest waits on omnihub's HubManifest taking "licenses" and the forge's licensing).
            'sources' => [(new Source(Source::UPM, $registry, ($this->hub['name'] ?? 'Forge') ?: null, $scopes, $this->urls->generate('forge_hub_catalog', [], UrlGeneratorInterface::ABSOLUTE_URL)))->toArray()],
            'games' => $this->urls->generate('forge_hub_catalog', [], UrlGeneratorInterface::ABSOLUTE_URL),
            // The device flow; and, with a payment gateway set, the one-click purchase (plan item 16):
            // the price to confirm, the purchase, the saved payment methods (docs/hub.md).
            'checkout' => ($this->hub['checkout'] ?? true) ? [
                'device' => $this->urls->generate('forge_hub_device', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'token' => $this->urls->generate('forge_hub_token', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ] + ($this->payments?->isEnabled() ? [
                'quote' => $this->urls->generate('forge_hub_quote', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'purchases' => $this->urls->generate('forge_hub_purchase', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'methods' => $this->urls->generate('forge_hub_payment_methods', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ] : []) : null,
            // The key's licences and their machines (licenses), and the licence API a machine
            // activates and releases itself through (docs/licensing.md).
            'licenses' => ($this->hub['checkout'] ?? true) ? [
                'list' => $this->urls->generate('forge_hub_licenses', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'activate' => $this->urls->generate('forge_api_license_activate', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'release' => $this->urls->generate('forge_api_license_release', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ] : null,
            'licensing' => $this->licensing($registry),
        ], fn ($value) => null !== $value);
    }

    /**
     * The key this forge signs its licences with, data only (plan item 14), as Forge Store pins
     * it on first use (item 17.1): its public key, the registry its tokens are bound to (item
     * 17.2) and, after a rotation, the previous key with its signature of the current one. Null
     * while no signing key is set.
     *
     * @return array<string, mixed>|null
     */
    public function licensing(?string $registry = null): ?array
    {
        $key = $this->tokens?->publicKey();
        if (null === $key) {
            return null;
        }

        return array_filter([
            'algorithm' => 'Ed25519',
            'publicKey' => $key,
            'registry' => $registry ?? $this->registry->url(),
        ] + ($this->tokens->rotation() ?? []), fn ($value) => null !== $value);
    }

    /**
     * The catalogue, as the caller sees it: every Unity package - a paid one listed with its
     * offers, "installable" when the caller's licences cover it - and every published game.
     *
     * @param list<License> $licenses the caller's valid licences (the Bearer key of the request)
     */
    public function catalog(array $licenses = []): array
    {
        $packages = [];
        foreach ($this->software->findUpmPackages() as $software) {
            if (!$software->isVisible() || !$this->registry->hasVersions($software)) {
                continue;
            }
            $releases = array_values(array_filter($software->getReleases()->toArray(), fn (Release $r) => $r->isPublished() && $r->getArtifact('upm')));
            usort($releases, fn (Release $a, Release $b) => version_compare($b->getVersion(), $a->getVersion()));
            $document = $this->registry->document($software, $licenses);
            $latest = $document['dist-tags']['latest'] ?? $releases[0]->getVersion();
            $manifest = \is_array($document['versions'][$latest] ?? null) ? $document['versions'][$latest] : [];

            $packages[] = array_filter([
                'name' => $software->getUpmName(),
                'displayName' => $manifest['displayName'] ?? $software->getName(),
                'description' => $manifest['description'] ?? $software->getTagline(),
                'keywords' => $manifest['keywords'] ?? null,
                'version' => $releases[0]->getVersion(),
                'versions' => array_map(fn (Release $release) => array_filter([
                    'version' => $release->getVersion(),
                    'date' => $release->getPublishedAt()?->format(\DATE_ATOM),
                    'changelog' => $release->getChangelog(),
                ]), $releases),
                'pricing' => $software->getPricing()->value,
                'installable' => \count($document['versions']) > 0,
                'image' => $this->absolute($software->getImage()),
                'screenshots' => array_map(fn (string $path) => $this->absolute($path), $software->getScreenshots()),
                'page' => $this->urls->generate('forge_software', ['slug' => $software->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'offers' => $software->isFree() ? null : $this->offers($software),
            ], fn ($value) => null !== $value && [] !== $value);
        }

        $games = [];
        foreach ($this->software->findGames() as $game) {
            $release = $game->getLatestRelease();
            $webgl = null;
            foreach ($game->getReleases() as $candidate) {
                if ($candidate->isPublished() && $candidate->getArtifact(Platform::WEBGL->value)) {
                    $webgl = $candidate;
                    break;
                }
            }
            $games[] = array_filter([
                'slug' => $game->getSlug(),
                'name' => $game->getName(),
                'tagline' => $game->getTagline(),
                'pricing' => $game->getPricing()->value,
                'version' => $release?->getVersion(),
                'image' => $this->absolute($game->getScreenshots()[0] ?? $game->getImage()),
                'page' => $this->urls->generate('forge_software', ['slug' => $game->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                // Played in the browser: a free game, or a paid one whose preview is open.
                'play' => $webgl && $game->isPlayableInBrowser() ? $this->urls->generate('forge_play', ['slug' => $game->getSlug(), 'version' => $webgl->getVersion()], UrlGeneratorInterface::ABSOLUTE_URL).'/' : null,
                'preview' => !$game->isFree() && $game->hasPlayablePreview() ? true : null,
                'platforms' => $release ? array_map(fn ($artifact) => $artifact->getFormat(), $release->getPlatformArtifacts()) : null,
                'offers' => $game->isFree() ? null : $this->offers($game),
            ], fn ($value) => null !== $value && [] !== $value);
        }

        return ['packages' => $packages, 'games' => $games];
    }

    /**
     * The licences of a key's holder, as a hub client shows them: each one's software (its slug,
     * which the licence API asks for), validity, seats, and the machines of that holder's seat -
     * each with the SHA-256 of its identifier, which the client compares with its own.
     *
     * @param list<License> $licenses
     */
    public function licenses(array $licenses, string $email): array
    {
        $list = [];
        foreach ($licenses as $license) {
            $software = $license->getSoftware();
            $seat = $license->findSeat($email);
            $list[] = array_filter([
                'id' => $license->getId(),
                'software' => $software ? array_filter(['slug' => $software->getSlug(), 'name' => $software->getName(), 'upm' => $software->getUpmName(), 'page' => $this->urls->generate('forge_software', ['slug' => $software->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL)]) : null,
                'status' => $license->getStatus()->value,
                'valid' => $license->isValid(),
                'expires' => $license->getExpiresAt()?->format(\DATE_ATOM),
                'updatesUntil' => $license->getUpdatesUntil()?->format(\DATE_ATOM),
                'seats' => $license->getSeats(),
                'machinesPerSeat' => $license->getMachinesPerSeat(),
                'machines' => $seat ? array_values(array_map(fn ($a) => array_filter([
                    'machine' => $a->getMachine(),
                    'name' => $a->getName(),
                    'platform' => $a->getPlatform(),
                    'lastSeen' => $a->getLastSeenAt()->format(\DATE_ATOM),
                ]), $seat->getActivations()->toArray())) : [],
            ], fn ($value) => null !== $value);
        }

        return ['email' => $email, 'licenses' => $list];
    }

    /** @return list<array<string, mixed>> */
    private function offers(Software $software): array
    {
        $offers = [];
        foreach ($software->getOffers() as $offer) {
            if (!$offer instanceof LicenseOffer || !$offer->isForSell()) {
                continue;
            }
            $offers[] = array_filter([
                'id' => $offer->getId(),
                'title' => $offer->getTitle(),
                'price' => $offer->getUnitPrice(),
                'currency' => $offer->getCurrency(),
                'seats' => $offer->getSeats(),
                'months' => $offer->getDurationMonths(),
                'updatesMonths' => $offer->getUpdatesMonths(),
            ], fn ($value) => null !== $value);
        }

        return $offers;
    }

    private function absolute(?string $path): ?string
    {
        return $path ? $this->urlHelper->getAbsoluteUrl($path) : null;
    }
}
