<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Base\Mapping as ORM;

/** One artifact handed out: who, under which licence, when. The IP is kept hashed. */
#[ORM\Entity]
#[ORM\Table(name: 'forge_download')]
#[ORM\Index(name: 'forge_download_at', fields: ['downloadedAt'])]
class Download
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Artifact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artifact $artifact;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\ManyToOne(targetEntity: License::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?License $license;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ipHash;

    /** web or composer */
    #[ORM\Column(length: 16)]
    private string $channel;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $downloadedAt;

    public function __construct(Artifact $artifact, ?User $user, ?License $license, ?string $ipHash, string $channel = 'web')
    {
        $this->artifact = $artifact;
        $this->user = $user;
        $this->license = $license;
        $this->ipHash = $ipHash;
        $this->channel = $channel;
        $this->downloadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getArtifact(): Artifact { return $this->artifact; }
    public function getUser(): ?User { return $this->user; }
    public function getLicense(): ?License { return $this->license; }
    public function getChannel(): string { return $this->channel; }
    public function getDownloadedAt(): \DateTimeImmutable { return $this->downloadedAt; }
}
