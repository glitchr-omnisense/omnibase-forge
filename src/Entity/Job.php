<?php

namespace Base\Forge\Entity;

use Base\Forge\Enum\PipelineStatus;
use Base\Mapping as ORM;

/** One thing a stage runs: a test suite, a build, a deployment. */
#[ORM\Entity]
#[ORM\Table(name: 'forge_pipeline_job')]
class Job implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stage::class, inversedBy: 'jobs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Stage $stage = null;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 16, enumType: PipelineStatus::class)]
    private PipelineStatus $status;

    /** Its failure does not fail the pipeline: it shows as a warning. */
    #[ORM\Column(name: 'allow_failure')]
    private bool $allowFailure = false;

    /**
     * The jobs it waits for, by name - whatever their stage. Empty: every job of the stage before.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $needs = [];

    /** Its log, where it ran. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $name = '', PipelineStatus $status = PipelineStatus::PENDING)
    {
        $this->name = $name;
        $this->status = $status;
    }

    public function __toString(): string { return $this->name; }

    public function getId(): ?int { return $this->id; }

    public function getStage(): ?Stage { return $this->stage; }
    public function setStage(?Stage $stage): self { $this->stage = $stage; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getStatus(): PipelineStatus { return $this->status; }
    public function setStatus(PipelineStatus $status): self { $this->status = $status; return $this; }

    /** Its status as its stage counts it: a failure that was allowed is a warning. */
    public function getEffectiveStatus(): PipelineStatus
    {
        return $this->allowFailure && PipelineStatus::FAILED === $this->status ? PipelineStatus::WARNING : $this->status;
    }

    public function isAllowFailure(): bool { return $this->allowFailure; }
    public function setAllowFailure(bool $allowFailure): self { $this->allowFailure = $allowFailure; return $this; }

    /** @return list<string> */
    public function getNeeds(): array { return $this->needs; }
    /** @param list<string> $needs */
    public function setNeeds(array $needs): self { $this->needs = array_values(array_unique(array_filter(array_map('strval', $needs)))); return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self { $this->url = $url ?: null; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self { $this->finishedAt = $finishedAt; return $this; }

    /** Seconds it ran; null before it ends. */
    public function getDuration(): ?int
    {
        return $this->startedAt && $this->finishedAt ? max(0, $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()) : null;
    }
}
