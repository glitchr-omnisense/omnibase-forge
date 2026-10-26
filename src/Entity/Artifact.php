<?php

namespace Base\Forge\Entity;

use Base\Forge\Enum\Platform;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file a release is downloaded as, stored under forge.storage_dir (never
 * in the web root) and handed out by DownloadController. Its format says
 * what it is: "zip", the tree at the tag (ArtifactBuilder); "upm", the same
 * tree as the npm tarball Unity's Package Manager installs (UpmRegistry);
 * or a platform's value (Platform: webgl, windows, macos, linux, android),
 * the build of a game or an application for it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_artifact')]
class Artifact implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Release::class, inversedBy: 'artifacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Release $release = null;

    /** Relative to forge.storage_dir: <software>/<version>/<filename>. */
    #[ORM\Column(length: 255)]
    private string $path = '';

    #[ORM\Column(length: 16)]
    private string $format = 'zip';

    #[ORM\Column(type: 'bigint')]
    private int|string $size = 0;

    #[ORM\Column(length: 64)]
    private string $sha256 = '';

    /** Hex: npm's dist.shasum (a "upm" tarball). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sha1 = null;

    /** Hex: npm's dist.integrity, as sha512-<base64> (a "upm" tarball). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $sha512 = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?Release $release = null, string $path = '', string $format = 'zip', int $size = 0, string $sha256 = '', ?string $sha1 = null, ?string $sha512 = null)
    {
        $this->path = $path;
        $this->format = $format;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->sha1 = $sha1;
        $this->sha512 = $sha512;
        $this->createdAt = new \DateTimeImmutable();
        $release?->addArtifact($this);
    }

    public function __toString(): string { return $this->getFilename(); }

    public function getId(): ?int { return $this->id; }

    public function getRelease(): ?Release { return $this->release; }
    public function setRelease(?Release $release): self { $this->release = $release; return $this; }

    public function getSoftware(): ?Software { return $this->release?->getSoftware(); }

    public function getPath(): string { return $this->path; }
    public function getFilename(): string { return basename($this->path); }

    public function getFormat(): string { return $this->format; }
    public function getSize(): int { return (int) $this->size; }
    public function getSha256(): string { return $this->sha256; }
    public function getSha1(): ?string { return $this->sha1; }
    public function getSha512(): ?string { return $this->sha512; }

    /** Subresource integrity, as npm writes it: sha512-<base64>. */
    public function getIntegrity(): ?string
    {
        return $this->sha512 ? 'sha512-'.base64_encode((string) hex2bin($this->sha512)) : null;
    }

    /** The platform it is the build for, or null: a source archive, a package. */
    public function getPlatform(): ?Platform { return Platform::tryFrom($this->format); }

    /** What it is served as. */
    public function getContentType(): string
    {
        return match (strtolower(pathinfo($this->path, PATHINFO_EXTENSION))) {
            'tgz', 'gz' => 'application/gzip',
            'apk' => 'application/vnd.android.package-archive',
            'dmg' => 'application/x-apple-diskimage',
            'aab' => 'application/octet-stream',
            default => 'application/zip',
        };
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isFree(): bool { return (bool) $this->getSoftware()?->isFree(); }
}
