<?php

namespace Base\Forge\Entity;

use Base\Forge\Enum\PipelineStatus;
use Base\Forge\Repository\PipelineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run of a project's checks on a commit - its tests, its build, its deployment -, wherever
 * it ran (a forge's CI, a script): its stages in order, each with its jobs. The forge keeps what
 * happened and shows it (a graph, drawn by @glitchr/graphjs); it runs nothing itself.
 */
#[ORM\Entity(repositoryClass: PipelineRepository::class)]
#[ORM\Table(name: 'forge_pipeline')]
#[ORM\UniqueConstraint(name: 'pipeline_source', columns: ['source', 'external_id'])]
#[ORM\Index(name: 'pipeline_commit', columns: ['commit_sha'])]
class Pipeline implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** What it is called where it ran: a GitHub workflow's name ("CI", "Docs"); null when there is one kind of run. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /** The branch or the tag it ran for. */
    #[ORM\Column(name: 'git_ref', length: 255, nullable: true)]
    private ?string $ref = null;

    #[ORM\Column(name: 'commit_sha', length: 40, nullable: true)]
    private ?string $commitSha = null;

    /** Where it ran ("gitlab", "github", "local"...), and what it is called there: the pair names it once. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(name: 'external_id', length: 64, nullable: true)]
    private ?string $externalId = null;

    /** Its page where it ran. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    /** Set by hand (canceled), or null: then it is what its stages say. */
    #[ORM\Column(length: 16, nullable: true, enumType: PipelineStatus::class)]
    private ?PipelineStatus $status = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, Stage> */
    #[ORM\OneToMany(targetEntity: Stage::class, mappedBy: 'pipeline', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $stages;

    public function __construct(?Project $project = null, ?string $ref = null, ?string $commitSha = null)
    {
        $this->project = $project;
        $this->ref = $ref;
        $this->commitSha = $commitSha;
        $this->createdAt = new \DateTimeImmutable();
        $this->stages = new ArrayCollection();
    }

    public function __toString(): string
    {
        return ltrim(sprintf('%s #%s %s', $this->name, $this->externalId ?? $this->id ?? '?', $this->ref ?? substr((string) $this->commitSha, 0, 8)));
    }

    public function getId(): ?int { return $this->id; }

    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $project): self { $this->project = $project; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name ?: null; return $this; }

    public function getRef(): ?string { return $this->ref; }
    public function setRef(?string $ref): self { $this->ref = $ref ?: null; return $this; }

    public function getCommitSha(): ?string { return $this->commitSha; }
    public function setCommitSha(?string $commitSha): self { $this->commitSha = $commitSha ?: null; return $this; }
    public function getShortSha(): ?string { return $this->commitSha ? substr($this->commitSha, 0, 8) : null; }

    public function getSource(): ?string { return $this->source; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function setSource(?string $source, ?string $externalId = null): self
    {
        $this->source = $source ?: null;
        $this->externalId = $externalId ?: null;

        return $this;
    }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self { $this->url = $url ?: null; return $this; }

    /** What was set by hand, or what the stages say. */
    public function getStatus(): PipelineStatus
    {
        return $this->status ?? PipelineStatus::of($this->stages->map(fn (Stage $stage) => $stage->getStatus()));
    }

    public function setStatus(?PipelineStatus $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self { $this->finishedAt = $finishedAt; return $this; }

    /** Seconds from its creation to its end; null while it runs. */
    public function getDuration(): ?int
    {
        return $this->finishedAt ? max(0, $this->finishedAt->getTimestamp() - $this->createdAt->getTimestamp()) : null;
    }

    /** @return Collection<int, Stage> in order */
    public function getStages(): Collection { return $this->stages; }

    /** The stage of that name, made at the end when there is none. */
    public function stage(string $name): Stage
    {
        foreach ($this->stages as $stage) {
            if ($stage->getName() === $name) {
                return $stage;
            }
        }
        $stage = new Stage($name, $this->stages->count());
        $stage->setPipeline($this);
        $this->stages->add($stage);

        return $stage;
    }

    /**
     * Keeps only the stages named, and in each the jobs named: what is left of a run brought up to
     * date by its source (a job no longer run is no longer shown).
     *
     * @param array<string, list<string>> $jobs stage name -> its job names
     */
    public function keep(array $jobs): self
    {
        foreach ($this->stages->toArray() as $stage) {
            if (!isset($jobs[$stage->getName()])) {
                $this->stages->removeElement($stage);
                continue;
            }
            $stage->keep($jobs[$stage->getName()]);
        }
        $position = 0;
        foreach ($this->stages as $stage) {
            $stage->setPosition($position++);
        }

        return $this;
    }

    /** @return list<Job> every job, stage after stage */
    public function getJobs(): array
    {
        $jobs = [];
        foreach ($this->stages as $stage) {
            foreach ($stage->getJobs() as $job) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }
}
