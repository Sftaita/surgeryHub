<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\HoursSource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot 1 (Exécution & Valorisation) — le RÉALISÉ d'une mission, distinct du PLANIFIÉ
 * (Mission.startAt/endAt) et de la VALORISATION FINANCIÈRE (future FinancialCalculation,
 * pas encore implémentée dans ce lot). Aucun montant, aucun tarif, aucun statut
 * financier ici — voir docs/decisions.md pour la séparation des trois réalités.
 *
 * Remplace InstrumentistService : renommage de table (migration
 * Version20260718...), pas une nouvelle table + copie. Les responsabilités mortes de
 * l'ancienne entité (serviceType, employmentTypeSnapshot, consultationFeeApplied,
 * computedAmount, statut financier CALCULATED/APPROVED/PAID) ont été supprimées —
 * aucun chemin de code de production n'en dépendait (vérifié avant migration).
 *
 * Relation Mission 1 — 0..1 MissionExecution : une mission peut ne pas encore avoir
 * de MissionExecution ; dans ce cas MissionExecutionService::resolveEffectiveDuration()
 * se replie sur les horaires planifiés de la Mission (voir cette méthode pour la règle
 * complète).
 *
 * Nommage délibérément non-ambigu (actualStartAt/actualEndAt/actualDurationMinutes,
 * jamais startAt/endAt/hours) pour ne jamais pouvoir être confondu avec le planifié.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mission_execution', indexes: [
    // EPIC Pilotage financier, Lot 7 (D-077) — date de rattachement de l'activité
    // (COALESCE(actual_start_at, mission.start_at)) sur tous les endpoints statistiques.
    new ORM\Index(name: 'idx_mission_execution_actual_start', columns: ['actual_start_at']),
])]
#[ORM\HasLifecycleCallbacks]
class MissionExecution
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['execution:read', 'execution:read_manager'])]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'execution')]
    #[ORM\JoinColumn(name: 'mission_id', nullable: false, unique: true)]
    private ?Mission $mission = null;

    /** Instant réel de début — voir App\Doctrine\Type\BusinessDateTimeImmutableType (D-066) : peut être soumis par un client, jamais un simple "now()" serveur. */
    #[ORM\Column(name: 'actual_start_at', type: 'business_datetime_immutable', nullable: true)]
    #[Groups(['execution:read', 'execution:read_manager'])]
    private ?\DateTimeImmutable $actualStartAt = null;

    #[ORM\Column(name: 'actual_end_at', type: 'business_datetime_immutable', nullable: true)]
    #[Groups(['execution:read', 'execution:read_manager'])]
    private ?\DateTimeImmutable $actualEndAt = null;

    /**
     * Toujours cohérente avec actualStartAt/actualEndAt quand les deux sont renseignés
     * (recalculée et écrasée par MissionExecutionService à chaque écriture des horaires
     * réels — jamais deux sources de vérité en parallèle) : fin − début − pause (D-143).
     * Peut aussi être renseignée seule, sans horaires réels connus (durée déclarée
     * explicite, saisies antérieures à D-143).
     */
    #[ORM\Column(name: 'actual_duration_minutes', type: 'integer', nullable: true)]
    #[Groups(['execution:read', 'execution:read_manager'])]
    private ?int $actualDurationMinutes = null;

    /**
     * D-143 — pause déclarée pendant la plage réelle (minutes). Seule information qui
     * sépare la plage début → fin de la durée prestée ; null = aucune pause déclarée.
     */
    #[ORM\Column(name: 'break_minutes', type: 'integer', nullable: true)]
    #[Groups(['execution:read', 'execution:read_manager'])]
    private ?int $breakMinutes = null;

    /**
     * D-156 — présence réelle partagée (prestation regroupée multi-salles). Quand elle est
     * renseignée, la présence est la SEULE source des heures réelles de cette mission :
     * actualStartAt/actualEndAt restent nuls (CHECK en base) et actualDurationMinutes porte
     * la part imputée à cette mission, dérivée de la présence (PresenceAllocator), écrite
     * uniquement par WorkedPresenceService.
     */
    #[ORM\ManyToOne(targetEntity: WorkedPresence::class, inversedBy: 'executions')]
    #[ORM\JoinColumn(name: 'presence_id', nullable: true, onDelete: 'RESTRICT')]
    private ?WorkedPresence $presence = null;

    #[ORM\Column(name: 'hours_source', enumType: HoursSource::class, nullable: true)]
    #[Groups(['execution:read', 'execution:read_manager'])]
    private ?HoursSource $hoursSource = null;

    /** @var Collection<int, MissionExecutionDispute> */
    #[ORM\OneToMany(mappedBy: 'missionExecution', targetEntity: MissionExecutionDispute::class, orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $disputes;

    public function __construct()
    {
        $this->disputes = new ArrayCollection();
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

    public function getActualStartAt(): ?\DateTimeImmutable
    {
        return $this->actualStartAt;
    }

    public function setActualStartAt(?\DateTimeImmutable $actualStartAt): static
    {
        $this->actualStartAt = $actualStartAt;
        return $this;
    }

    public function getActualEndAt(): ?\DateTimeImmutable
    {
        return $this->actualEndAt;
    }

    public function setActualEndAt(?\DateTimeImmutable $actualEndAt): static
    {
        $this->actualEndAt = $actualEndAt;
        return $this;
    }

    public function getActualDurationMinutes(): ?int
    {
        return $this->actualDurationMinutes;
    }

    public function setActualDurationMinutes(?int $actualDurationMinutes): static
    {
        $this->actualDurationMinutes = $actualDurationMinutes;
        return $this;
    }

    public function getBreakMinutes(): ?int
    {
        return $this->breakMinutes;
    }

    public function setBreakMinutes(?int $breakMinutes): static
    {
        $this->breakMinutes = $breakMinutes;
        return $this;
    }

    /**
     * D-143 — début ET fin réels connus : seule forme acceptée à la soumission.
     * D-156 — pour une mission liée à une présence partagée, ce sont les horaires de la
     * présence (la mission n'a pas d'horaires propres).
     */
    public function hasCompleteActualTimes(): bool
    {
        if ($this->presence !== null) {
            return $this->presence->hasCompleteTimes();
        }

        return $this->actualStartAt !== null && $this->actualEndAt !== null;
    }

    public function getPresence(): ?WorkedPresence
    {
        return $this->presence;
    }

    public function setPresence(?WorkedPresence $presence): static
    {
        $this->presence = $presence;
        return $this;
    }

    public function getHoursSource(): ?HoursSource
    {
        return $this->hoursSource;
    }

    public function setHoursSource(?HoursSource $hoursSource): static
    {
        $this->hoursSource = $hoursSource;
        return $this;
    }

    /** @return Collection<int, MissionExecutionDispute> */
    public function getDisputes(): Collection
    {
        return $this->disputes;
    }
}
