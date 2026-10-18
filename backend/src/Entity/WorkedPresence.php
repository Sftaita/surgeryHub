<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-156 — présence physique RÉELLE et unique d'un instrumentiste pour une prestation
 * regroupée multi-salles (même instrumentiste, même chirurgien, même site, même date
 * locale, missions BLOC qui se chevauchent).
 *
 * Trois notions jamais confondues :
 * - l'horaire opérationnel de chaque Mission (Mission.startAt/endAt, le planifié) ;
 * - la présence réelle (CETTE entité — seule source de vérité des heures réelles des
 *   missions qui y sont liées) ;
 * - la part imputée à chaque mission (MissionExecution.actualDurationMinutes des missions
 *   liées), dérivée de la présence par PresenceAllocator et réécrite UNIQUEMENT par
 *   WorkedPresenceService — jamais saisie.
 *
 * Une mission liée (MissionExecution.presence non nul) n'a jamais ses propres horaires
 * réels (contrainte CHECK en base) : aucune double source d'heures.
 *
 * Le regroupement prévisionnel, lui, n'est jamais persisté (PrestationGroupResolver) ;
 * seuls les liens des missions dont la présence a été déclarée le sont.
 *
 * `revision` (verrou optimiste Doctrine) s'incrémente à chaque modification : figée dans
 * les snapshots financiers, elle permet de détecter une valorisation devenue obsolète.
 */
#[ORM\Entity]
#[ORM\Table(name: 'worked_presence')]
#[ORM\HasLifecycleCallbacks]
class WorkedPresence
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $instrumentist = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $site = null;

    /** Date locale (Europe/Brussels) de début des missions de la prestation. */
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $serviceDate = null;

    /** Brouillon possible (début seul, D-143) ; jamais soumissible sans la fin. */
    #[ORM\Column(name: 'actual_start_at', type: 'business_datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $actualStartAt = null;

    #[ORM\Column(name: 'actual_end_at', type: 'business_datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $actualEndAt = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $revision = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $declaredBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    /** @var Collection<int, MissionExecution> */
    #[ORM\OneToMany(mappedBy: 'presence', targetEntity: MissionExecution::class)]
    private Collection $executions;

    public function __construct()
    {
        $this->executions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getInstrumentist(): ?User { return $this->instrumentist; }
    public function setInstrumentist(User $instrumentist): static { $this->instrumentist = $instrumentist; return $this; }

    public function getSurgeon(): ?User { return $this->surgeon; }
    public function setSurgeon(User $surgeon): static { $this->surgeon = $surgeon; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function getServiceDate(): ?\DateTimeImmutable { return $this->serviceDate; }
    public function setServiceDate(\DateTimeImmutable $serviceDate): static { $this->serviceDate = $serviceDate; return $this; }

    public function getActualStartAt(): ?\DateTimeImmutable { return $this->actualStartAt; }
    public function setActualStartAt(?\DateTimeImmutable $actualStartAt): static { $this->actualStartAt = $actualStartAt; return $this; }

    public function getActualEndAt(): ?\DateTimeImmutable { return $this->actualEndAt; }
    public function setActualEndAt(?\DateTimeImmutable $actualEndAt): static { $this->actualEndAt = $actualEndAt; return $this; }

    public function hasCompleteTimes(): bool
    {
        return $this->actualStartAt !== null && $this->actualEndAt !== null && $this->actualEndAt > $this->actualStartAt;
    }

    /** Durée réelle rémunérable (fin − début, aucune pause — D-146 bis), null tant qu'incomplète. */
    public function getTotalMinutes(): ?int
    {
        if (!$this->hasCompleteTimes()) {
            return null;
        }

        return (int) round(($this->actualEndAt->getTimestamp() - $this->actualStartAt->getTimestamp()) / 60);
    }

    public function getRevision(): int { return $this->revision; }

    public function getDeclaredBy(): ?User { return $this->declaredBy; }
    public function setDeclaredBy(User $declaredBy): static { $this->declaredBy = $declaredBy; return $this; }

    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $updatedBy): static { $this->updatedBy = $updatedBy; return $this; }

    /** @return Collection<int, MissionExecution> */
    public function getExecutions(): Collection { return $this->executions; }

    /** @return list<Mission> missions liées, ordre stable par id */
    public function getMissions(): array
    {
        $missions = [];
        foreach ($this->executions as $execution) {
            if ($execution->getMission() !== null) {
                $missions[] = $execution->getMission();
            }
        }
        usort($missions, static fn (Mission $a, Mission $b) => (int) $a->getId() <=> (int) $b->getId());

        return $missions;
    }
}
