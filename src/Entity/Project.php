<?php

namespace Base\Forge\Entity;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread;
use Base\Entity\User;
use Base\Forge\Enum\CardColumn;
use Base\Forge\Enum\ProjectStatus;
use Base\Forge\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A client's project: what the dashboard shows them - the board, the time
 * the studio logged on it, and the history of its git repository. A
 * base-bundle thread: its title and excerpt are translated, its client is
 * also its owner (base-bundle's own notion of whose a thread is).
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[DiscriminatorEntry(value: 'forge_project')]
class Project extends Thread
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-diagram-project'];
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?User $client = null;

    /**
     * Where the repository is cloned from (git:sync, the warmer) when it is
     * not declared in git.repositories: forge.repositories_dir/<repository>.git.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $repositoryUrl = null;

    /** The name git/git-bundle knows the repository by. */
    #[ORM\Column(length: 96, nullable: true)]
    protected ?string $gitRepository = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ProjectStatus::class)]
    protected ?ProjectStatus $status = ProjectStatus::OPEN;

    /** Minutes agreed for the project; null when it runs on support hours only. */
    #[ORM\Column(nullable: true)]
    protected ?int $budgetMinutes = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    protected ?\DateTimeImmutable $dueOn = null;

    /**
     * In cents, before VAT: the project is invoiced on the time spent, at this
     * rate an hour (Service\HourInvoices), and its time takes nothing off the
     * client's support hours. Null: support hours, or the agreed budget.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $hourlyRate = null;

    /** @var Collection<int, Card> */
    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $cards;

    /** @var Collection<int, TimeEntry> */
    #[ORM\OneToMany(targetEntity: TimeEntry::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['spentOn' => 'DESC'])]
    protected Collection $timeEntries;

    public function __construct(?User $client = null, ?string $title = null, ?string $slug = null)
    {
        parent::__construct($client, null, $title, $slug);
        $this->client = $client;
        $this->cards = new ArrayCollection();
        $this->timeEntries = new ArrayCollection();
    }

    public function getClient(): ?User { return $this->client; }
    public function setClient(User $client): self
    {
        if ($this->client && $this->client !== $client) {
            $this->removeOwner($this->client);
        }
        $this->client = $client;
        $this->addOwner($client);

        return $this;
    }

    /** The title, as the dashboard calls it. */
    public function getName(): string { return (string) $this->getTitle(); }
    public function setName(string $name): self { $this->setTitle($name); return $this; }

    /** One line under the name: the thread's (translated) excerpt. */
    public function getSummary(): ?string { return $this->getExcerpt(); }
    public function setSummary(?string $summary): self { $this->setExcerpt($summary); return $this; }

    public function getRepositoryUrl(): ?string { return $this->repositoryUrl; }
    public function setRepositoryUrl(?string $repositoryUrl): self { $this->repositoryUrl = $repositoryUrl ?: null; return $this; }

    public function getGitRepository(): ?string { return $this->gitRepository; }
    public function setGitRepository(?string $gitRepository): self { $this->gitRepository = $gitRepository ?: null; return $this; }

    public function getStatus(): ProjectStatus { return $this->status ?? ProjectStatus::OPEN; }
    public function setStatus(ProjectStatus $status): self { $this->status = $status; return $this; }

    public function getBudgetMinutes(): ?int { return $this->budgetMinutes; }
    public function setBudgetMinutes(?int $budgetMinutes): self { $this->budgetMinutes = $budgetMinutes; return $this; }

    public function getDueOn(): ?\DateTimeImmutable { return $this->dueOn; }
    public function setDueOn(?\DateTimeImmutable $dueOn): self { $this->dueOn = $dueOn; return $this; }

    public function getHourlyRate(): ?int { return $this->hourlyRate; }
    public function setHourlyRate(?int $hourlyRate): self { $this->hourlyRate = $hourlyRate ?: null; return $this; }

    /** Whether its time is invoiced by the hour rather than taken off support hours. */
    public function isInvoicedOnTime(): bool { return null !== $this->hourlyRate; }

    /** @return Collection<int, Card> */
    public function getCards(): Collection { return $this->cards; }

    /** @return array<string, list<Card>> the board, column by column, in position order */
    public function getBoard(): array
    {
        $board = [];
        foreach (CardColumn::cases() as $column) {
            $board[$column->value] = [];
        }
        foreach ($this->cards as $card) {
            $board[$card->getColumn()->value][] = $card;
        }

        return $board;
    }

    public function addCard(Card $card): self
    {
        if (!$this->cards->contains($card)) {
            $this->cards->add($card);
            $card->setProject($this);
        }

        return $this;
    }

    /** @return Collection<int, TimeEntry> newest first */
    public function getTimeEntries(): Collection { return $this->timeEntries; }

    public function addTimeEntry(TimeEntry $entry): self
    {
        if (!$this->timeEntries->contains($entry)) {
            $this->timeEntries->add($entry);
            $entry->setProject($this);
        }

        return $this;
    }

    public function getSpentMinutes(): int
    {
        return array_sum($this->timeEntries->map(fn (TimeEntry $entry) => $entry->getMinutes())->toArray());
    }

    /** 0-100: the share of cards done, or of the budget spent when there are no cards. */
    public function getProgress(): int
    {
        $cards = $this->cards->count();
        if ($cards > 0) {
            $done = $this->cards->filter(fn (Card $card) => CardColumn::DONE === $card->getColumn())->count();

            return (int) round(100 * $done / $cards);
        }
        if ($this->budgetMinutes) {
            return (int) min(100, round(100 * $this->getSpentMinutes() / $this->budgetMinutes));
        }

        return ProjectStatus::DONE === $this->getStatus() ? 100 : 0;
    }
}
