<?php

namespace Base\Forge\Entity;

use Base\Forge\Enum\CardColumn;
use Base\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** A task on a project's board. The client and the studio both move them. */
#[ORM\Entity]
#[ORM\Table(name: 'forge_card')]
class Card implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'board_column', length: 16, enumType: CardColumn::class)]
    private CardColumn $column = CardColumn::BACKLOG;

    #[ORM\Column]
    private int $position = 0;

    /** A short label shown on the card (Design, Dev, Copy...). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title = '', CardColumn $column = CardColumn::BACKLOG)
    {
        $this->title = $title;
        $this->column = $column;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->title; }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): self { $this->project = $project; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getColumn(): CardColumn { return $this->column; }
    public function getPosition(): int { return $this->position; }

    public function moveTo(CardColumn $column, int $position): self
    {
        $this->column = $column;
        $this->position = max(0, $position);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setColumn(CardColumn $column): self { return $this->moveTo($column, $this->position); }
    public function setPosition(int $position): self { $this->position = max(0, $position); return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = $label; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
