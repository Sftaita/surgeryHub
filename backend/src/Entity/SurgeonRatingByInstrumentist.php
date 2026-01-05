<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(
    name: 'surgeon_rating_by_instrumentist',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_surgeon_rating_mission_instrumentist', columns: ['mission_id', 'instrumentist_user_id'])],
    indexes: [new ORM\Index(name: 'idx_surgeon_rating_mission', columns: ['mission_id'])]
)]
#[ORM\HasLifecycleCallbacks]
class SurgeonRatingByInstrumentist
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['rating:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'surgeonRatings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rating:read'])]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, name: 'surgeon_user_id')]
    #[Groups(['rating:read'])]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, name: 'instrumentist_user_id')]
    #[Groups(['rating:read'])]
    private ?User $instrumentist = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['rating:read'])]
    private ?int $cordiality = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['rating:read'])]
    private ?int $punctuality = null;

    #[ORM\Column(type: 'smallint', name: 'mission_respect')]
    #[Groups(['rating:read'])]
    private ?int $missionRespect = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['rating:read'])]
    private ?string $comment = null;

    #[ORM\Column]
    #[Groups(['rating:read'])]
    private bool $isFirstCollaboration = false;

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

    public function getSurgeon(): ?User
    {
        return $this->surgeon;
    }

    public function setSurgeon(User $surgeon): static
    {
        $this->surgeon = $surgeon;

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

    public function getCordiality(): ?int
    {
        return $this->cordiality;
    }

    public function setCordiality(int $cordiality): static
    {
        $this->cordiality = $cordiality;

        return $this;
    }

    public function getPunctuality(): ?int
    {
        return $this->punctuality;
    }

    public function setPunctuality(int $punctuality): static
    {
        $this->punctuality = $punctuality;

        return $this;
    }

    public function getMissionRespect(): ?int
    {
        return $this->missionRespect;
    }

    public function setMissionRespect(int $missionRespect): static
    {
        $this->missionRespect = $missionRespect;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function isFirstCollaboration(): bool
    {
        return $this->isFirstCollaboration;
    }

    public function setIsFirstCollaboration(bool $isFirstCollaboration): static
    {
        $this->isFirstCollaboration = $isFirstCollaboration;

        return $this;
    }
}
