<?php

namespace Base\Forge\Entity;

use Base\Forge\Entity\Product\HourPack;
use Base\Forge\Repository\QuoteRepository;
use Base\Marketplace\Entity\Quote\AbstractQuote;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Base\Mapping as ORM;

/**
 * A special offer made to one client: lines of hours at a rate, a discount,
 * a date it holds until. Asked for from the quote form, priced in the back
 * office, read and accepted by the client - which turns it into a one-off
 * HourPack in their cart (Forge\Service\QuoteToOrder); paying that order
 * credits the hours and marks the quote paid.
 *
 * What every quote is (its client and company, its status, discount,
 * validity, token) is omnibase/marketplace's AbstractQuote, mapped here on
 * the forge's own table: the hours and the HourPack are the forge's.
 */
#[ORM\Entity(repositoryClass: QuoteRepository::class)]
#[ORM\Table(name: 'forge_quote')]
class Quote extends AbstractQuote
{
    /** @var Collection<int, QuoteLine> */
    #[ORM\OneToMany(targetEntity: QuoteLine::class, mappedBy: 'quote', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** The one-off product accepting it put in the client's cart. */
    #[ORM\OneToOne(targetEntity: HourPack::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HourPack $product = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    public function __construct(string $reference = '')
    {
        parent::__construct($reference);
        $this->lines = new ArrayCollection();
    }

    /** Hours to sell: a quote without any cannot be accepted. */
    public function hasSomethingToSell(): bool
    {
        return $this->getTotalMinutes() > 0;
    }

    /** @return Collection<int, QuoteLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(QuoteLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $line->setPosition($this->lines->count());
            $this->lines->add($line);
            $line->setQuote($this);
        }

        return $this;
    }

    public function removeLine(QuoteLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    public function getTotalMinutes(): int
    {
        return array_sum($this->lines->map(fn (QuoteLine $line) => $line->getMinutes())->toArray());
    }

    /** Before the discount, in cents. */
    public function getSubtotal(): int
    {
        return array_sum($this->lines->map(fn (QuoteLine $line) => $line->getAmount())->toArray());
    }

    public function getProduct(): ?HourPack { return $this->product; }
    public function setProduct(?HourPack $product): self { $this->product = $product; return $this; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): self { $this->project = $project; return $this; }
}
