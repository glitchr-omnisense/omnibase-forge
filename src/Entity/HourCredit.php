<?php

namespace Base\Forge\Entity;

use Base\Entity\User;
use Base\Forge\Enum\CreditReason;
use Base\Forge\Repository\HourCreditRepository;
use Base\Mapping as ORM;

/**
 * One line of a client's support-hour ledger: positive when hours are bought,
 * negative when time is logged. The balance is their sum; nothing else
 * stores it, so it can never drift from its history.
 */
#[ORM\Entity(repositoryClass: HourCreditRepository::class)]
#[ORM\Table(name: 'forge_hour_credit')]
class HourCredit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Signed minutes. */
    #[ORM\Column]
    private int $minutes;

    #[ORM\Column(length: 16, enumType: CreditReason::class)]
    private CreditReason $reason;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    #[ORM\OneToOne(targetEntity: TimeEntry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?TimeEntry $timeEntry = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, int $minutes, CreditReason $reason, ?string $note = null)
    {
        $this->user = $user;
        $this->minutes = $minutes;
        $this->reason = $reason;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    /** A time entry's debit follows it to another client's project (TimeEntryLedgerSubscriber). */
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getMinutes(): int { return $this->minutes; }
    public function setMinutes(int $minutes): self { $this->minutes = $minutes; return $this; }
    public function getReason(): CreditReason { return $this->reason; }
    public function getNote(): ?string { return $this->note; }

    public function getOrderReference(): ?string { return $this->orderReference; }
    public function setOrderReference(?string $orderReference): self { $this->orderReference = $orderReference; return $this; }

    public function getTimeEntry(): ?TimeEntry { return $this->timeEntry; }
    public function setTimeEntry(?TimeEntry $timeEntry): self { $this->timeEntry = $timeEntry; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
