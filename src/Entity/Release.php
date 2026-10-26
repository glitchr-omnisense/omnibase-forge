<?php

namespace Base\Forge\Entity;

use Base\Forge\Enum\Platform;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of a piece of software: a git tag, the commit it points at,
 * the tag's message as changelog, and the archives built from that tree.
 * Created by forge:release:sync from the repository's tags, or by hand.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_release')]
#[ORM\UniqueConstraint(name: 'forge_release_version', fields: ['software', 'version'])]
class Release implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Software::class, inversedBy: 'releases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Software $software = null;

    #[ORM\Column(length: 64)]
    private string $version = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $tag = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $commitSha = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $changelog = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var Collection<int, Artifact> */
    #[ORM\OneToMany(targetEntity: Artifact::class, mappedBy: 'release', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $artifacts;

    public function __construct(?Software $software = null, string $version = '')
    {
        $this->artifacts = new ArrayCollection();
        $this->version = $version;
        $software?->addRelease($this);
    }

    public function __toString(): string
    {
        return trim(($this->software?->getName() ?? '').' '.$this->version);
    }

    public function getId(): ?int { return $this->id; }

    public function getSoftware(): ?Software { return $this->software; }
    public function setSoftware(?Software $software): self { $this->software = $software; return $this; }

    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): self { $this->version = $version; return $this; }

    public function getTag(): ?string { return $this->tag; }
    public function setTag(?string $tag): self { $this->tag = $tag; return $this; }

    public function getCommitSha(): ?string { return $this->commitSha; }
    public function setCommitSha(?string $commitSha): self { $this->commitSha = $commitSha; return $this; }

    public function getChangelog(): ?string { return $this->changelog; }
    public function setChangelog(?string $changelog): self { $this->changelog = $changelog; return $this; }

    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self { $this->publishedAt = $publishedAt; return $this; }
    public function isPublished(): bool { return null !== $this->publishedAt && $this->publishedAt <= new \DateTimeImmutable(); }

    /** @return Collection<int, Artifact> */
    public function getArtifacts(): Collection { return $this->artifacts; }

    public function addArtifact(Artifact $artifact): self
    {
        if (!$this->artifacts->contains($artifact)) {
            $this->artifacts->add($artifact);
            $artifact->setRelease($this);
        }

        return $this;
    }

    /** @return list<Artifact> its builds, one per platform, in Platform's order */
    public function getPlatformArtifacts(): array
    {
        $builds = [];
        foreach (Platform::cases() as $platform) {
            if ($artifact = $this->getArtifact($platform->value)) {
                $builds[] = $artifact;
            }
        }

        return $builds;
    }

    public function getArtifact(string $format = 'zip'): ?Artifact
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->getFormat() === $format) {
                return $artifact;
            }
        }

        return null;
    }
}
