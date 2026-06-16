<?php

namespace App\Entity;

use App\Enum\UserAuditEventType;
use App\Repository\UserAuditEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserAuditEventRepository::class)]
#[ORM\Table(name: 'user_audit_event', indexes: [
    new ORM\Index(name: 'idx_user_audit_actor',       columns: ['actor_id']),
    new ORM\Index(name: 'idx_user_audit_target',      columns: ['target_user_id']),
    new ORM\Index(name: 'idx_user_audit_created_at',  columns: ['created_at']),
    new ORM\Index(name: 'idx_user_audit_event_type',  columns: ['event_type']),
])]
class UserAuditEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $targetUser = null;

    #[ORM\Column(length: 50, enumType: UserAuditEventType::class)]
    private ?UserAuditEventType $eventType = null;

    #[ORM\Column(length: 500)]
    private string $description = '';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getActor(): ?User { return $this->actor; }
    public function setActor(User $actor): static { $this->actor = $actor; return $this; }

    public function getTargetUser(): ?User { return $this->targetUser; }
    public function setTargetUser(?User $targetUser): static { $this->targetUser = $targetUser; return $this; }

    public function getEventType(): ?UserAuditEventType { return $this->eventType; }
    public function setEventType(UserAuditEventType $eventType): static { $this->eventType = $eventType; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }

    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $payload): static { $this->payload = $payload; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
