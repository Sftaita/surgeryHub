<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot 5 (D-068) : rattachement au référentiel InterventionType.
 *
 * `code`/`label` deviennent un **instantané figé à la création** (copié depuis
 * `interventionType->getCode()`/`getLabel()` par InterventionService, jamais relu
 * depuis le référentiel par la suite) — c'est ce qui garantit que l'affichage
 * historique d'une intervention ne change jamais si le manager renomme ou désactive
 * le type ensuite ailleurs dans la configuration. Pour les lignes créées avant ce lot
 * (ex: mission #529, `code="LCA"`, non mappée — voir migration Version20260717210000),
 * `code`/`label` restent l'unique source de vérité (`interventionType` = null,
 * *legacy read-only*) : elles ne sont ni recalculées ni supprimées.
 *
 * `interventionType` est **obligatoire pour tout nouvel encodage** (imposé par
 * InterventionService::create(), pas par une contrainte NOT NULL en base — voir la
 * migration pour la raison). `primaryFirm` reste toujours facultatif.
 */
#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_intervention_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class MissionIntervention
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'interventions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Mission $mission = null;

    /** Instantané (snapshot), voir docblock de la classe — jamais relu depuis le référentiel. */
    #[ORM\Column(length: 100)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $code = null;

    /** Instantané (snapshot), voir docblock de la classe — jamais relu depuis le référentiel. */
    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $label = null;

    /** Null uniquement pour les lignes historiques pré-Lot 5 (legacy). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'intervention_type_id', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?InterventionType $interventionType = null;

    /** Toujours facultative. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'primary_firm_id', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Firm $primaryFirm = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $orderIndex = 0;

    /**
     * @var Collection<int, MaterialLine>
     */
    #[ORM\OneToMany(mappedBy: 'missionIntervention', targetEntity: MaterialLine::class)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $materialLines;

    public function __construct()
    {
        $this->materialLines = new ArrayCollection();
    }

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getOrderIndex(): ?int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;
        return $this;
    }

    public function getInterventionType(): ?InterventionType
    {
        return $this->interventionType;
    }

    public function setInterventionType(?InterventionType $interventionType): static
    {
        $this->interventionType = $interventionType;
        return $this;
    }

    public function getPrimaryFirm(): ?Firm
    {
        return $this->primaryFirm;
    }

    public function setPrimaryFirm(?Firm $primaryFirm): static
    {
        $this->primaryFirm = $primaryFirm;
        return $this;
    }

    /**
     * @return Collection<int, MaterialLine>
     */
    public function getMaterialLines(): Collection
    {
        return $this->materialLines;
    }
}
