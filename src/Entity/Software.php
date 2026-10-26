<?php

namespace Base\Forge\Entity;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Attribute\Uploader;
use Base\Entity\Thread;
use Base\Entity\User;
use Base\Enum\ThreadState;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Enum\SoftwareStatus;
use Base\Forge\Repository\SoftwareRepository;
use Base\Service\Model\LinkableInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something the studio made and shows: a client site, a bundle, a JavaScript
 * library, a tool. A base-bundle thread, as market's stores and products are:
 * its title, headline and content are translated (FR/EN), its slug and state
 * come with it - published, it is on the applications page. With a git
 * repository it gets releases; FREE ones download for anyone, LICENSED ones
 * against a licence bought through one of its LicenseOffer products. Its
 * kind says how it is delivered: a package (Composer, Unity's Package
 * Manager), a game (played in the page, downloaded per platform) or an
 * application.
 */
#[ORM\Entity(repositoryClass: SoftwareRepository::class)]
#[DiscriminatorEntry(value: 'forge_software')]
class Software extends Thread implements LinkableInterface
{
    public const CATEGORIES = ['site', 'bundle', 'javascript', 'tool', 'game'];

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-cubes'];
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getRouter()->generate('forge_software', array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\Choice(choices: self::CATEGORIES)]
    protected ?string $category = 'site';

    #[ORM\Column(length: 16, nullable: true, enumType: SoftwareStatus::class)]
    protected ?SoftwareStatus $status = SoftwareStatus::LIVE;

    #[ORM\Column(length: 16, nullable: true, enumType: Pricing::class)]
    protected ?Pricing $pricing = Pricing::FREE;

    #[ORM\Column(length: 16, nullable: true, enumType: SoftwareKind::class)]
    protected ?SoftwareKind $kind = SoftwareKind::PACKAGE;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $stack = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url]
    protected ?string $homepage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url]
    protected ?string $sourceUrl = null;

    /**
     * Where the repository is cloned from (git:sync, the warmer) when it is
     * not declared in git.repositories: forge.repositories_dir/<repository>.git.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $repositoryUrl = null;

    /** The name git/git-bundle knows the repository by (git.repositories.<name>). */
    #[ORM\Column(length: 96, nullable: true)]
    protected ?string $gitRepository = null;

    /** vendor/name in the Composer repository, for a PHP package. */
    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Regex('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/')]
    protected ?string $packageName = null;

    /**
     * Its name in the Unity registry (UpmRegistry, /upm), for a Unity
     * package: com.glitchr.<name>, as its package.json says.
     */
    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Regex('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/')]
    protected ?string $upmName = null;

    /**
     * The card's picture: an upload (the back office, or the screenshot
     * command), stored by base-bundle's Uploader as a uuid - or, left as they
     * were (missable), a path under the public assets or an absolute URL.
     * Untyped: the form hands the setter an UploadedFile.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['image/*'], missable: true)]
    protected $image = null;

    /**
     * A game's screenshots, uploads stored by base-bundle's Uploader (uuids),
     * shown on its page in their order. Untyped, as the image.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Uploader(max_size: '8MB', mime_types: ['image/*'])]
    protected $screenshots = [];

    /**
     * A paid game's playable preview: its WebGL build played in its page by anyone. Off by
     * default - a free game plays in the browser, a paid one is downloaded once bought.
     */
    #[ORM\Column(nullable: true)]
    protected ?bool $playablePreview = false;

    /** A game's trailer: an uploaded video, or (missable) a URL to one. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Uploader(max_size: '256MB', mime_types: ['video/*'], missable: true)]
    protected $trailer = null;

    /** The live application, shown in a nested panel from the card. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url]
    protected ?string $demoUrl = null;

    #[ORM\Column(nullable: true)]
    protected ?int $position = 0;

    #[ORM\Column(length: 4, nullable: true)]
    protected ?string $year = null;

    /** @var Collection<int, Release> */
    #[ORM\OneToMany(targetEntity: Release::class, mappedBy: 'software', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['publishedAt' => 'DESC'])]
    protected Collection $releases;

    /** @var Collection<int, LicenseOffer> */
    #[ORM\OneToMany(targetEntity: LicenseOffer::class, mappedBy: 'software')]
    protected Collection $offers;


    public function __construct(?User $owner = null, ?string $title = null, ?string $slug = null)
    {
        parent::__construct($owner, null, $title, $slug);
        $this->releases = new ArrayCollection();
        $this->offers = new ArrayCollection();
    }

    /**
     * Its family (Thread's parent): base-bundle-admin, -market,
     * base-plugin... under base-bundle. The pages list a family under its
     * head, not side by side with it. Never its own ancestor.
     */
    public function setParent(?Thread $parent): self
    {
        for ($ancestor = $parent; $ancestor; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $this) {
                throw new \InvalidArgumentException(sprintf('%s cannot be filed under %s: it would be its own ancestor.', $this->getName(), $parent->getTitle()));
            }
        }
        parent::setParent($parent);

        return $this;
    }

    /** @return list<Software> its published children, for the public pages */
    public function getVisibleChildren(): array
    {
        return array_values($this->getChildren()->filter(fn ($child) => $child instanceof self && ThreadState::PUBLISH === $child->getState())->toArray());
    }

    /** Listed on its own: no parent, or one that is not published. */
    public function isHead(): bool
    {
        $parent = $this->getParent();

        return !$parent instanceof self || ThreadState::PUBLISH !== $parent->getState();
    }

    /** The title, as the templates and the admin call it. */
    public function getName(): string { return (string) $this->getTitle(); }
    public function setName(string $name): self { $this->setTitle($name); return $this; }

    /** The one-liner under the name: the thread's (translated) headline. */
    public function getTagline(): ?string { return $this->getHeadline(); }
    public function setTagline(?string $tagline): self { $this->setHeadline($tagline); return $this; }

    /** The long text: the thread's (translated) content. */
    public function getDescription(): ?string { return $this->getContent(); }
    public function setDescription(?string $description): self { $this->setContent($description); return $this; }

    /**
     * Publish (or withdraw) it: on the applications page and downloadable
     * once published - Thread::isVisible() then says so, drafts staying
     * visible to their owners and the admins.
     */
    public function publish(bool $visible = true): self
    {
        $this->setState($visible ? ThreadState::PUBLISH : ThreadState::DRAFT);
        if ($visible && !$this->getPublishedAt()) {
            $this->setPublishedAt(new \DateTime());
        }

        return $this;
    }

    public function getCategory(): string { return $this->category ?? 'site'; }
    public function setCategory(string $category): self
    {
        if (!\in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown software category "%s".', $category));
        }
        $this->category = $category;

        return $this;
    }

    public function getStatus(): SoftwareStatus { return $this->status ?? SoftwareStatus::LIVE; }
    public function setStatus(SoftwareStatus $status): self { $this->status = $status; return $this; }

    public function getKind(): SoftwareKind { return $this->kind ?? SoftwareKind::PACKAGE; }
    public function setKind(SoftwareKind $kind): self { $this->kind = $kind; return $this; }
    public function isGame(): bool { return SoftwareKind::GAME === $this->getKind(); }

    public function hasPlayablePreview(): bool { return (bool) $this->playablePreview; }
    public function getPlayablePreview(): bool { return (bool) $this->playablePreview; }
    public function setPlayablePreview(?bool $preview): self { $this->playablePreview = (bool) $preview; return $this; }

    /**
     * Whether its WebGL build is played in its page: a free game always; a paid one only when
     * the studio opened a playable preview of it - otherwise it is downloaded once bought.
     */
    public function isPlayableInBrowser(): bool { return $this->isFree() || $this->hasPlayablePreview(); }

    public function getPricing(): Pricing { return $this->pricing ?? Pricing::FREE; }
    public function setPricing(Pricing $pricing): self { $this->pricing = $pricing; return $this; }
    public function isFree(): bool { return $this->getPricing()->isFree(); }

    /** @return list<string> */
    public function getStack(): array { return $this->stack ?? []; }
    /** @param list<string> $stack */
    public function setStack(array $stack): self { $this->stack = array_values(array_filter(array_map('trim', $stack))); return $this; }

    /** The stack as the back office edits it: "Symfony, TransparentJS". */
    public function getStackAsText(): string { return implode(', ', $this->getStack()); }
    public function setStackAsText(?string $stack): self { return $this->setStack(explode(',', (string) $stack)); }

    public function getHomepage(): ?string { return $this->homepage; }
    public function setHomepage(?string $homepage): self { $this->homepage = $homepage; return $this; }

    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function setSourceUrl(?string $sourceUrl): self { $this->sourceUrl = $sourceUrl; return $this; }

    public function getRepositoryUrl(): ?string { return $this->repositoryUrl; }
    public function setRepositoryUrl(?string $repositoryUrl): self { $this->repositoryUrl = $repositoryUrl ?: null; return $this; }

    public function getGitRepository(): ?string { return $this->gitRepository; }
    public function setGitRepository(?string $gitRepository): self { $this->gitRepository = $gitRepository ?: null; return $this; }

    public function getPackageName(): ?string { return $this->packageName; }
    public function setPackageName(?string $packageName): self { $this->packageName = $packageName; return $this; }

    public function getUpmName(): ?string { return $this->upmName; }
    public function setUpmName(?string $upmName): self { $this->upmName = $upmName ?: null; return $this; }

    /** The picture's public path (an upload), or the stored path or URL as it is. */
    public function getImage(): ?string { return Uploader::getPublic($this, 'image'); }
    public function getImageFile() { return Uploader::get($this, 'image'); }
    public function setImage($image): self { $this->image = $image; return $this; }

    /** @return list<string> the screenshots' public paths, in their order */
    public function getScreenshots(): array { return array_values(array_filter((array) Uploader::getPublic($this, 'screenshots'))); }
    public function getScreenshotFiles() { return Uploader::get($this, 'screenshots'); }
    public function setScreenshots($screenshots): self { $this->screenshots = $screenshots; return $this; }

    /** The trailer's public path (an upload), or the stored URL as it is. */
    public function getTrailer(): ?string { return Uploader::getPublic($this, 'trailer'); }
    public function getTrailerFile() { return Uploader::get($this, 'trailer'); }
    public function setTrailer($trailer): self { $this->trailer = $trailer; return $this; }

    public function getDemoUrl(): ?string { return $this->demoUrl; }
    public function setDemoUrl(?string $demoUrl): self { $this->demoUrl = $demoUrl; return $this; }

    public function getPosition(): int { return (int) $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function getYear(): ?string { return $this->year; }
    public function setYear(?string $year): self { $this->year = $year; return $this; }

    /** @return Collection<int, Release> newest first */
    public function getReleases(): Collection { return $this->releases; }

    public function addRelease(Release $release): self
    {
        if (!$this->releases->contains($release)) {
            $this->releases->add($release);
            $release->setSoftware($this);
        }

        return $this;
    }

    /** A release never saved, taken back (its build failed): the next flush must not cascade it in. */
    public function removeRelease(Release $release): self
    {
        $this->releases->removeElement($release);

        return $this;
    }

    public function getLatestRelease(): ?Release
    {
        foreach ($this->releases as $release) {
            if ($release->isPublished()) {
                return $release;
            }
        }

        return null;
    }

    public function findRelease(string $version): ?Release
    {
        foreach ($this->releases as $release) {
            if ($release->getVersion() === $version) {
                return $release;
            }
        }

        return null;
    }

    /** @return Collection<int, LicenseOffer> */
    public function getOffers(): Collection { return $this->offers; }
}
