<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_mission_claim', columns: ['mission_id'])], indexes: [new ORM\Index(name: 'idx_claim_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class MissionClaim
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read_manager'])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'claim')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $instrumentist = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mission:read_manager'])]
    private ?\DateTimeImmutable $claimedAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getInstrumentist(): ?User
    {
        return $this->instrumentist;
    }

    public function setInstrumentist(User $instrumentist): static
    {
        $this->instrumentist = $instrumentist;

        return $this;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(\DateTimeImmutable $claimedAt): static
    {
        $this->claimedAt = $claimedAt;

        return $this;
    }
}
