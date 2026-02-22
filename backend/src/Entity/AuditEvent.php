<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\AuditEventType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_event', indexes: [
    new ORM\Index(name: 'idx_audit_mission', columns: ['mission_id']),
    new ORM\Index(name: 'idx_audit_actor', columns: ['actor_id']),
])]
#[ORM\HasLifecycleCallbacks]
class AuditEvent
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $actor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\Column(enumType: AuditEventType::class)]
    private ?AuditEventType $eventType = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(User $actor): static
    {
        $this->actor = $actor;
        return $this;
    }

    public function getMission(): ?Mission
    {
        return $this->mission;
    }

    public function setMission(Mission $mission): static
    {
        $this->mission = $mission;
        return $this;
    }

    public function getEventType(): ?AuditEventType
    {
        return $this->eventType;
    }

    public function setEventType(AuditEventType $eventType): static
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;
        return $this;
    }
}