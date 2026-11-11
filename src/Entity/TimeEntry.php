<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Time the studio spent on a project. Logging it takes the same minutes off
 * the client's support hours (Forge\Service\HourLedger, through
 * TimeEntrySubscriber), so the balance on their dashboard is always the sum
 * of what they bought and what was done - unless its project is invoiced on
 * the time spent (Project::$hourlyRate): then an invoice of hours bills it,
 * and it knows the line that did (HourInvoice, $invoiceLine).
 */
#[ORM\Entity]
#[ORM\Table(name: 'forge_time_entry')]
class TimeEntry implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'timeEntries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column]
    #[Assert\Positive]
    private int $minutes = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $note = '';

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Regex('/^[0-9a-f]{7,40}$/')]
    private ?string $commitSha = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $spentOn;

    /** Whether it counts against the client's support hours (false: covered by the project's budget). */
    #[ORM\Column]
    private bool $billable = true;

    /**
     * The invoice of hours that billed it (a draft: that reserves it), on a
     * project invoiced on the time spent; null when it is not invoiced, or
     * was released by a credit note.
     */
    #[ORM\ManyToOne(targetEntity: HourInvoice::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HourInvoice $hourInvoice = null;

    /** The line of the issued invoice that billed it (its "id" in Invoice::getLines()). */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $invoiceLine = null;

    public function __construct(int $minutes = 0, string $note = '', ?\DateTimeImmutable $spentOn = null)
    {
        $this->minutes = $minutes;
        $this->note = $note;
        $this->spentOn = $spentOn ?? new \DateTimeImmutable('today');
    }

    public function __toString(): string
    {
        return sprintf('%s · %s', $this->note, self::formatMinutes($this->minutes));
    }

    public static function formatMinutes(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%dh%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): self { $this->project = $project; return $this; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): self { $this->author = $author; return $this; }

    public function getMinutes(): int { return $this->minutes; }
    public function setMinutes(int $minutes): self { $this->minutes = $minutes; return $this; }

    public function getNote(): string { return $this->note; }
    public function setNote(string $note): self { $this->note = $note; return $this; }

    public function getCommitSha(): ?string { return $this->commitSha; }
    public function setCommitSha(?string $commitSha): self { $this->commitSha = $commitSha ?: null; return $this; }

    public function getSpentOn(): \DateTimeImmutable { return $this->spentOn; }
    public function setSpentOn(\DateTimeImmutable $spentOn): self { $this->spentOn = $spentOn; return $this; }

    public function isBillable(): bool { return $this->billable; }
    public function setBillable(bool $billable): self { $this->billable = $billable; return $this; }

    public function getHourInvoice(): ?HourInvoice { return $this->hourInvoice; }
    public function getInvoiceLine(): ?string { return $this->invoiceLine; }

    /** Whether an issued invoice billed it (not a draft's reservation). */
    public function isInvoiced(): bool { return null !== $this->invoiceLine; }

    /** Reserved by a draft, or released (null): HourInvoice::addEntry(), removeEntry(). */
    public function billBy(?HourInvoice $hourInvoice): self
    {
        $this->hourInvoice = $hourInvoice;
        if (null === $hourInvoice) {
            $this->invoiceLine = null;
        }

        return $this;
    }

    /** Billed by that line of the issued invoice (Service\HourInvoices::issue()). */
    public function billedOn(string $line): self
    {
        if (null === $this->hourInvoice) {
            throw new \LogicException('A time entry is billed on the line of its invoice.');
        }
        $this->invoiceLine = $line;

        return $this;
    }
}
