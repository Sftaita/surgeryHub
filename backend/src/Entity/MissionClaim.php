<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class MissionClaim
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * IMPORTANT:
     * inversedBy doit pointer vers "claims"
     * (propriété exacte dans Mission)
     */
    #[ORM\ManyToOne(inversedBy: 'claims')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $instrumentist = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $claimedAt = null;

    public function __construct()
    {
        $this->claimedAt = new \DateTimeImmutable();
    }

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
