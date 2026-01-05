<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\PublicationChannel;
use App\Enum\PublicationScope;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_publication_mission', columns: ['mission_id'])])]
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
}
