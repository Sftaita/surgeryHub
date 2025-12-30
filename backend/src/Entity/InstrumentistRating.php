<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'instrumentist_rating',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_instrumentist_rating_mission_surgeon', columns: ['mission_id', 'surgeon_user_id'])],
    indexes: [new ORM\Index(name: 'idx_instrumentist_rating_site', columns: ['site_id'])]
)]
#[ORM\HasLifecycleCallbacks]
class InstrumentistRating
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $site = null;

    #[ORM\ManyToOne(inversedBy: 'instrumentistRatings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, name: 'surgeon_user_id')]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, name: 'instrumentist_user_id')]
    private ?User $instrumentist = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $sterilityRespect = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $equipmentKnowledge = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $attitude = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $punctuality = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private bool $isFirstCollaboration = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Hospital
    {
        return $this->site;
    }

    public function setSite(Hospital $site): static
    {
        $this->site = $site;

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

    public function getSterilityRespect(): ?int
    {
        return $this->sterilityRespect;
    }

    public function setSterilityRespect(int $sterilityRespect): static
    {
        $this->sterilityRespect = $sterilityRespect;

        return $this;
    }

    public function getEquipmentKnowledge(): ?int
    {
        return $this->equipmentKnowledge;
    }

    public function setEquipmentKnowledge(int $equipmentKnowledge): static
    {
        $this->equipmentKnowledge = $equipmentKnowledge;

        return $this;
    }

    public function getAttitude(): ?int
    {
        return $this->attitude;
    }

    public function setAttitude(int $attitude): static
    {
        $this->attitude = $attitude;

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
