<?php

namespace Base\Forge\Entity;

use Base\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** One line of a quote: a piece of work, its hours, the rate. */
#[ORM\Entity]
#[ORM\Table(name: 'forge_quote_line')]
class QuoteLine implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $minutes = 0;

    /** Per hour, in cents. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $hourlyRate = 0;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(string $label = '', int $minutes = 0, int $hourlyRate = 0)
    {
        $this->label = $label;
        $this->minutes = $minutes;
        $this->hourlyRate = $hourlyRate;
    }

    public function __toString(): string { return $this->label; }

    public function getId(): ?int { return $this->id; }

    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): self { $this->quote = $quote; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }

    public function getMinutes(): int { return $this->minutes; }
    public function setMinutes(int $minutes): self { $this->minutes = $minutes; return $this; }

    /** Hours as the admin form edits them (1.5 = 1h30). */
    public function getHours(): float { return $this->minutes / 60; }
    public function setHours(float $hours): self { $this->minutes = (int) round($hours * 60); return $this; }

    public function getHourlyRate(): int { return $this->hourlyRate; }
    public function setHourlyRate(int $hourlyRate): self { $this->hourlyRate = $hourlyRate; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    /** In cents. */
    public function getAmount(): int
    {
        return (int) round($this->hourlyRate * $this->minutes / 60);
    }
}
