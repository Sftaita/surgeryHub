<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Hospital
{
    use TimestampableTrait;

    public const DEFAULT_TIMEZONE = 'Europe/Brussels';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['hospital:read', 'mission:read', 'mission:read_manager', 'rating:read', 'export:read', 'site:list'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['hospital:read', 'mission:read', 'mission:read_manager', 'rating:read', 'export:read', 'site:list'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['hospital:read', 'mission:read_manager', 'site:list'])]
    private ?string $address = null;

    // IMPORTANT: nullable=true pour tolérer DB existante, mais getter garantit une valeur non vide
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['hospital:read', 'mission:read', 'mission:read_manager', 'export:read', 'site:list'])]
    private ?string $timezone = null;

    /**
     * @var Collection<int, SiteMembership>
     */
    #[ORM\OneToMany(mappedBy: 'site', targetEntity: SiteMembership::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $memberships;

    /**
     * @var Collection<int, Mission>
     */
    #[ORM\OneToMany(mappedBy: 'site', targetEntity: Mission::class)]
    private Collection $missions;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
        $this->missions = new ArrayCollection();
    }

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
        $this->name = $name;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    /**
     * Toujours renvoyer une timezone exploitable (jamais "" / null).
     */
    public function getTimezone(): string
    {
        $tz = trim((string) ($this->timezone ?? ''));
        return $tz !== '' ? $tz : self::DEFAULT_TIMEZONE;
    }

    /**
     * Setter tolérant: si vide => null (le getter fera le fallback).
     */
    public function setTimezone(?string $timezone): static
    {
        $tz = trim((string) ($timezone ?? ''));
        $this->timezone = $tz !== '' ? $tz : null;

        return $this;
    }

    /**
     * @return Collection<int, SiteMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(SiteMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setSite($this);
        }
        return $this;
    }

    public function removeMembership(SiteMembership $membership): static
    {
        if ($this->memberships->removeElement($membership)) {
            if ($membership->getSite() === $this) {
                $membership->setSite(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Mission>
     */
    public function getMissions(): Collection
    {
        return $this->missions;
    }
}
