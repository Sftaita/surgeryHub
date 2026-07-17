<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Référentiel médical fermé (Lot 1 — socle catalogue financier).
 * Indépendant des firmes et des règles tarifaires — voir docs/decisions.md.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'intervention_type',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_intervention_type_code', columns: ['code']),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class InterventionType
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['intervention_type:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['intervention_type:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    #[Groups(['intervention_type:read'])]
    private ?string $label = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['intervention_type:read'])]
    private ?string $specialty = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['intervention_type:read'])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * Le code n'est modifiable qu'à la création — aucun setter n'est appelé après
     * persist() ailleurs dans le code (voir InterventionTypeService::create()).
     * Contrainte volontairement documentée ici plutôt qu'imposée par le langage :
     * Doctrine n'offre pas de "readonly après persist" natif pour ce cas.
     */
    public function setCode(string $code): static
    {
        $this->code = strtoupper(trim($code));
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = trim($label);
        return $this;
    }

    public function getSpecialty(): ?string
    {
        return $this->specialty;
    }

    public function setSpecialty(?string $specialty): static
    {
        $this->specialty = $specialty;
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
}
