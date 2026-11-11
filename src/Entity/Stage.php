<?php

namespace Base\Forge\Entity;

use Base\Forge\Enum\PipelineStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A step of a pipeline ("test", "build", "deploy"): the jobs that run together, after the stage before. */
#[ORM\Entity]
#[ORM\Table(name: 'forge_pipeline_stage')]
class Stage implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pipeline::class, inversedBy: 'stages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pipeline $pipeline = null;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column]
    private int $position;

    /** @var Collection<int, Job> */
    #[ORM\OneToMany(targetEntity: Job::class, mappedBy: 'stage', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $jobs;

    public function __construct(string $name = '', int $position = 0)
    {
        $this->name = $name;
        $this->position = max(0, $position);
        $this->jobs = new ArrayCollection();
    }

    public function __toString(): string { return $this->name; }

    public function getId(): ?int { return $this->id; }

    public function getPipeline(): ?Pipeline { return $this->pipeline; }
    public function setPipeline(?Pipeline $pipeline): self { $this->pipeline = $pipeline; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = max(0, $position); return $this; }

    /** @return Collection<int, Job> */
    public function getJobs(): Collection { return $this->jobs; }

    /** The job of that name, made when there is none. */
    public function job(string $name, PipelineStatus $status = PipelineStatus::PENDING): Job
    {
        foreach ($this->jobs as $job) {
            if ($job->getName() === $name) {
                return $job->setStatus($status);
            }
        }
        $job = new Job($name, $status);
        $job->setStage($this);
        $this->jobs->add($job);

        return $job;
    }

    /** @param list<string> $names the jobs to keep; the others are removed */
    public function keep(array $names): self
    {
        foreach ($this->jobs->toArray() as $job) {
            if (!\in_array($job->getName(), $names, true)) {
                $this->jobs->removeElement($job);
            }
        }

        return $this;
    }

    /** What its jobs say; one that failed and was allowed to is a warning. */
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::of($this->jobs->map(fn (Job $job) => $job->getEffectiveStatus()));
    }
}
