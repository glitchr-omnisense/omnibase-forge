<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Base\Forge\Enum\HourInvoiceStatus;
use Base\Forge\Repository\HourInvoiceRepository;
use Base\Marketplace\Entity\Invoice;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The hours a Partner is invoiced for over a period: first a draft - the
 * time entries of their projects invoiced on the time spent
 * (Project::$hourlyRate), logged in the period and not invoiced yet,
 * reserved for it - which a person reviews; then issued as an
 * omnibase/marketplace Invoice (the same entity, numbering, PDF, Factur-X
 * and transmission as the shop's: Service\HourInvoices). Once issued only
 * its state follows the invoice's; a correction is a credit note, which
 * releases the hours for another invoice.
 *
 * A period lies within one calendar month: a periodic invoice covers the
 * services of one month and is issued by its end (CGI art. 289, I-3;
 * BOI-TVA-DECLA-30-20-10-40 §80-100).
 */
#[ORM\Entity(repositoryClass: HourInvoiceRepository::class)]
#[ORM\Table(name: 'forge_hour_invoice')]
class HourInvoice implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** The Partner's account: the client of the projects invoiced. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $client = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodEnd;

    /** In percent: 20.0 in France's normal rate; 0 when the buyer reverse-charges or the seller is exempt. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private float $vatRate = 20.0;

    #[ORM\Column(length: 16, enumType: HourInvoiceStatus::class)]
    private HourInvoiceStatus $status = HourInvoiceStatus::DRAFT;

    /** For the person reviewing it; not printed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    /** Its invoice, and its credit note: never deleted while an invoice of hours names them (an issued invoice is kept). */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Invoice $creditNote = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TimeEntry> what it invoices (a draft: what it reserves) */
    #[ORM\OneToMany(targetEntity: TimeEntry::class, mappedBy: 'hourInvoice')]
    #[ORM\OrderBy(['spentOn' => 'ASC', 'id' => 'ASC'])]
    private Collection $entries;

    public function __construct(?User $client = null, ?\DateTimeImmutable $periodStart = null, ?\DateTimeImmutable $periodEnd = null)
    {
        $this->client = $client;
        // Last month by default: the month just closed is the one to invoice.
        $this->periodStart = $periodStart ?? new \DateTimeImmutable('first day of last month midnight');
        $this->periodEnd = $periodEnd ?? $this->periodStart->modify('last day of this month');
        $this->createdAt = new \DateTimeImmutable();
        $this->entries = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->invoice?->getNumber() ?? sprintf('%s · %s → %s', $this->client?->getUserIdentifier() ?? '?', $this->periodStart->format('d/m/Y'), $this->periodEnd->format('d/m/Y'));
    }

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->periodEnd < $this->periodStart) {
            $context->buildViolation('The period ends before it starts.')->atPath('periodEnd')->addViolation();
        } elseif ($this->periodStart->format('Y-m') !== $this->periodEnd->format('Y-m')) {
            // CGI art. 289, I-3: one invoice for the services of one calendar month.
            $context->buildViolation('A periodic invoice covers one calendar month (CGI art. 289, I-3).')->atPath('periodEnd')->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getClient(): ?User { return $this->client; }
    public function setClient(?User $client): self { $this->client = $client; return $this; }

    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $periodStart): self { $this->periodStart = $periodStart; return $this; }

    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $periodEnd): self { $this->periodEnd = $periodEnd; return $this; }

    public function getVatRate(): float { return $this->vatRate; }
    public function setVatRate(float $vatRate): self { $this->vatRate = $vatRate; return $this; }

    public function getStatus(): HourInvoiceStatus { return $this->status; }

    public function isDraft(): bool { return HourInvoiceStatus::DRAFT === $this->status; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note ?: null; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getCreditNote(): ?Invoice { return $this->creditNote; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, TimeEntry> */
    public function getEntries(): Collection { return $this->entries; }

    public function getMinutes(): int
    {
        return array_sum($this->entries->map(fn (TimeEntry $entry) => $entry->getMinutes())->toArray());
    }

    /** Reserved for this draft. */
    public function addEntry(TimeEntry $entry): self
    {
        if (!$this->isDraft()) {
            throw new \LogicException(sprintf('The invoice %s is issued: its hours do not change.', $this));
        }
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
            $entry->billBy($this);
        }

        return $this;
    }

    /** Released: no longer reserved, nor invoiced. */
    public function removeEntry(TimeEntry $entry): self
    {
        if ($this->entries->removeElement($entry)) {
            $entry->billBy(null);
        }

        return $this;
    }

    /** Issued: the numbered invoice now stands for it. */
    public function issuedAs(Invoice $invoice): self
    {
        if (!$this->isDraft()) {
            throw new \LogicException(sprintf('%s is not a draft.', $this));
        }
        $this->invoice = $invoice;
        $this->status = HourInvoiceStatus::ISSUED;

        return $this;
    }

    /**
     * Cancelled by its credit note: its time entries released - the line that
     * billed them is gone with the invoice - for another invoice.
     *
     * @return list<TimeEntry> the entries released
     */
    public function cancelledBy(Invoice $creditNote): array
    {
        if (HourInvoiceStatus::ISSUED !== $this->status) {
            throw new \LogicException(sprintf('%s is not issued.', $this));
        }
        $this->creditNote = $creditNote;
        $this->status = HourInvoiceStatus::CANCELLED;
        $released = $this->entries->toArray();
        foreach ($released as $entry) {
            $this->removeEntry($entry);
        }

        return array_values($released);
    }
}
