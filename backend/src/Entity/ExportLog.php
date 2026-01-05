<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_export_log_user', columns: ['user_id'])])]
#[ORM\HasLifecycleCallbacks]
class ExportLog
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['export:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['export:read'])]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    #[Groups(['export:read'])]
    private ?string $outputType = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['export:read'])]
    private ?array $filters = null;

    #[ORM\Column(length: 100)]
    #[Groups(['export:read'])]
    private ?string $eventType = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['export:read'])]
    private bool $success = true;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['export:read'])]
    private ?string $errorMessage = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOutputType(): ?string
    {
        return $this->outputType;
    }

    public function setOutputType(string $outputType): static
    {
        $this->outputType = $outputType;

        return $this;
    }

    public function getFilters(): ?array
    {
        return $this->filters;
    }

    public function setFilters(?array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    public function getEventType(): ?string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}
