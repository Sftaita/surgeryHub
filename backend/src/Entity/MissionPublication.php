<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\PublicationChannel;
use App\Enum\PublicationCloseReason;
use App\Enum\PublicationScope;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_publication_mission', columns: ['mission_id']),
    new ORM\Index(name: 'idx_publication_priority_due', columns: ['closed_at', 'declined_at', 'expires_at']),
])]
#[ORM\HasLifecycleCallbacks]
class MissionPublication
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'publications')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?Mission $mission = null;

    #[ORM\Column(enumType: PublicationScope::class)]
    #[Groups(['mission:read_manager'])]
    private ?PublicationScope $scope = null;

    #[ORM\Column(enumType: PublicationChannel::class)]
    #[Groups(['mission:read_manager'])]
    private ?PublicationChannel $channel = null;

    #[ORM\ManyToOne]
    #[Groups(['mission:read_manager'])]
    private ?User $targetInstrumentist = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mission:read_manager'])]
    private ?\DateTimeImmutable $publishedAt = null;

    /**
     * D-125 — set when the TARGETED instrumentist explicitly declines the offer
     * (MissionDispatchService::declineOffer()). A declined publication no longer grants
     * that instrumentist the right to see/claim the mission; it is kept (never deleted)
     * as the trace of the refusal. Always null for POOL publications.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read_manager'])]
    private ?\DateTimeImmutable $declinedAt = null;

    /**
     * D-177 — deadline of an exclusive priority (room take-over: the usual instrumentist has
     * 48 real hours from the durable issue of the request). Null for every other publication
     * (D-125 requests keep no deadline, POOL never has one).
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read_manager'])]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * D-177 — set when the request stops granting its target anything for a reason other
     * than her own refusal (declinedAt): acceptance, expiry, absence, manager lift… The row
     * is kept as the trace; `closeReason` says why.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read_manager'])]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 32, nullable: true, enumType: PublicationCloseReason::class)]
    #[Groups(['mission:read_manager'])]
    private ?PublicationCloseReason $closeReason = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMission(): ?Mission
    {
        return $this->mission;
    }

    public function setMission(?Mission $mission): static
    {
        $this->mission = $mission;

        return $this;
    }

    public function getScope(): ?PublicationScope
    {
        return $this->scope;
    }

    public function setScope(PublicationScope $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getChannel(): ?PublicationChannel
    {
        return $this->channel;
    }

    public function setChannel(PublicationChannel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getTargetInstrumentist(): ?User
    {
        return $this->targetInstrumentist;
    }

    public function setTargetInstrumentist(?User $targetInstrumentist): static
    {
        $this->targetInstrumentist = $targetInstrumentist;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getDeclinedAt(): ?\DateTimeImmutable
    {
        return $this->declinedAt;
    }

    public function setDeclinedAt(?\DateTimeImmutable $declinedAt): static
    {
        $this->declinedAt = $declinedAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCloseReason(): ?PublicationCloseReason
    {
        return $this->closeReason;
    }

    public function close(PublicationCloseReason $reason, \DateTimeImmutable $at): static
    {
        $this->closedAt = $at;
        $this->closeReason = $reason;

        return $this;
    }

    /** D-177 — an exclusive priority with a deadline (room take-over), not a plain D-125 request. */
    public function isPriority(): bool
    {
        return $this->expiresAt !== null;
    }

    /**
     * D-125 — single rule shared by every publication consumer (MissionVoter,
     * MissionActionsService, MissionService::list(eligibleToMe), InstrumentistMissionSyncService):
     * a publication grants visibility/claim rights only while it has not been declined.
     * D-177 — nor closed (accepted, expired, lifted…).
     */
    public function isActive(): bool
    {
        return $this->declinedAt === null && $this->closedAt === null;
    }
}
