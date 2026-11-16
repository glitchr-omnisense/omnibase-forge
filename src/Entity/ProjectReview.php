<?php

namespace Base\Forge\Entity;

use Base\Forge\Repository\ProjectReviewRepository;
use Base\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the client thinks of a delivered project. The studio asks for it
 * from the back office (the project is then closed: delivered, its board
 * no longer moves); the client answers through their link - one to five
 * stars, a few words -; the studio decides whether it shows on the site.
 */
#[ORM\Entity(repositoryClass: ProjectReviewRepository::class)]
#[ORM\Table(name: 'forge_project_review')]
class ProjectReview implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** The client's link: opaque, no account needed. */
    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 5)]
    private ?int $rating = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $comment = null;

    /** How it is signed on the site: "Camille, Atelier Mercier". */
    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $signature = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** Shown on the site: the studio's choice. */
    #[ORM\Column]
    private bool $published = false;

    public function __construct(?Project $project = null)
    {
        $this->project = $project;
        $this->token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return ($this->project?->getName() ?? '').($this->rating ? ' · '.str_repeat('★', $this->rating) : '');
    }

    public function getId(): ?int { return $this->id; }
    public function getProject(): ?Project { return $this->project; }
    public function getToken(): string { return $this->token; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }

    /** Asked again (the first e-mail lost): a new link, the old one void. */
    public function renew(): self
    {
        $this->token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->requestedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRating(): ?int { return $this->rating; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment ?: null; return $this; }
    public function getSignature(): ?string { return $this->signature; }
    public function setSignature(?string $signature): self { $this->signature = $signature ?: null; return $this; }
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function isSubmitted(): bool { return null !== $this->submittedAt; }

    /** The client's answer: once. */
    public function submit(int $rating, ?string $comment, ?string $signature): self
    {
        if ($this->isSubmitted()) {
            throw new \LogicException('This review is already given.');
        }
        $this->rating = max(1, min(5, $rating));
        $this->setComment($comment);
        $this->setSignature($signature);
        $this->submittedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): self { $this->published = $published && $this->isSubmitted(); return $this; }
}
