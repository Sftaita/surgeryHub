<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
class Absence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $user = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $dateStart;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $dateEnd;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['planning:read'])]
    private ?string $reason = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getDateStart(): \DateTimeImmutable { return $this->dateStart; }
    public function setDateStart(\DateTimeImmutable $dateStart): static { $this->dateStart = $dateStart; return $this; }

    public function getDateEnd(): \DateTimeImmutable { return $this->dateEnd; }
    public function setDateEnd(\DateTimeImmutable $dateEnd): static { $this->dateEnd = $dateEnd; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
