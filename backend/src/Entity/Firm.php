<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(
    name: 'firm',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_firm_name', columns: ['name'])
    ]
)]
#[ORM\HasLifecycleCallbacks]
class Firm
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $name = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private bool $active = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billingEmail = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $billingEmailCc = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function getBillingEmail(): ?string
    {
        return $this->billingEmail;
    }

    public function setBillingEmail(?string $billingEmail): static
    {
        $this->billingEmail = $billingEmail;
        return $this;
    }

    public function getBillingEmailCc(): ?array
    {
        return $this->billingEmailCc;
    }

    public function setBillingEmailCc(?array $billingEmailCc): static
    {
        $this->billingEmailCc = $billingEmailCc;
        return $this;
    }
}
