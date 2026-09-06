<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\AbsenceCommunicationType;
use App\Repository\SurgeonAbsenceCommunicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Journal des communications d'absence chirurgien (Lot A/D-114 — §18 de la demande).
 *
 * Modèle parent/deliveries : cette ligne représente LA décision logique "annoncer telle
 * occurrence à tel site pour telle absence" — immuable une fois créée (snapshot). L'état
 * réel d'envoi par destinataire (statut, tentatives, erreur) vit sur
 * SurgeonAbsenceCommunicationDelivery, jamais ici : pour "Libération de salle", plusieurs
 * chirurgiens collègues reçoivent chacun un email individuel distinct, avec un statut de
 * livraison propre (un échec pour l'un ne doit jamais masquer le succès d'un autre).
 *
 * `revisionNumber` + la contrainte unique (absence, site, type, revision) garantissent
 * l'idempotence au niveau DB : 0 pour le premier envoi d'un type donné, incrémenté pour
 * chaque complément légitime (ex. un allongement de congé révélant de nouvelles occurrences
 * BLOCK jamais annoncées) — jamais un simple bouton désactivé côté frontend.
 *
 * `absence` est ON DELETE SET NULL (même convention que
 * PlanningOccurrenceException::sourceAbsence) : la suppression d'une Absence ne doit jamais
 * effacer ou bloquer cet historique.
 */
#[ORM\Entity(repositoryClass: SurgeonAbsenceCommunicationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(
    name: 'surgeon_absence_communication',
    uniqueConstraints: [new ORM\UniqueConstraint(
        name: 'uniq_absence_site_type_revision',
        columns: ['absence_id', 'site_id', 'type', 'revision_number'],
    )],
)]
#[ORM\Index(columns: ['surgeon_id'], name: 'idx_absence_comm_surgeon')]
#[ORM\Index(columns: ['site_id'], name: 'idx_absence_comm_site')]
class SurgeonAbsenceCommunication
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    /** ON DELETE SET NULL — une Absence supprimée ne doit jamais effacer cet historique. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'absence_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['planning:read'])]
    private ?Absence $absence = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(enumType: AbsenceCommunicationType::class, length: 40)]
    #[Groups(['planning:read'])]
    private AbsenceCommunicationType $type;

    #[ORM\Column(type: 'integer')]
    #[Groups(['planning:read'])]
    private int $revisionNumber = 0;

    #[ORM\Column(length: 255)]
    #[Groups(['planning:read'])]
    private string $subjectSnapshot;

    #[ORM\Column(type: 'text')]
    #[Groups(['planning:read'])]
    private string $bodySnapshot;

    /**
     * Occurrences BLOCK annoncées par CETTE ligne précisément (pas le cumul des révisions
     * précédentes) — sert de base au calcul du delta lors d'un allongement de congé
     * ultérieur (AbsenceCommunicationJournalService::alreadyAnnouncedOccurrenceKeys()), qui
     * a besoin de `postId` (identité de la récurrence) ET `date` pour construire une clé
     * univoque par occurrence.
     *
     * @var array<int, array{postId: int, date: string, period: string}>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['planning:read'])]
    private array $occurrencesSnapshot = [];

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $absenceDateStartSnapshot;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $absenceDateEndSnapshot;

    /** @var Collection<int, SurgeonAbsenceCommunicationDelivery> */
    #[ORM\OneToMany(mappedBy: 'communication', targetEntity: SurgeonAbsenceCommunicationDelivery::class, cascade: ['persist'])]
    #[Groups(['planning:read'])]
    private Collection $deliveries;

    public function __construct()
    {
        $this->deliveries = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getAbsence(): ?Absence { return $this->absence; }
    public function setAbsence(?Absence $absence): static { $this->absence = $absence; return $this; }

    public function getSurgeon(): ?User { return $this->surgeon; }
    public function setSurgeon(User $surgeon): static { $this->surgeon = $surgeon; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function getType(): AbsenceCommunicationType { return $this->type; }
    public function setType(AbsenceCommunicationType $type): static { $this->type = $type; return $this; }

    public function getRevisionNumber(): int { return $this->revisionNumber; }
    public function setRevisionNumber(int $revisionNumber): static { $this->revisionNumber = $revisionNumber; return $this; }

    public function getSubjectSnapshot(): string { return $this->subjectSnapshot; }
    public function setSubjectSnapshot(string $subjectSnapshot): static { $this->subjectSnapshot = $subjectSnapshot; return $this; }

    public function getBodySnapshot(): string { return $this->bodySnapshot; }
    public function setBodySnapshot(string $bodySnapshot): static { $this->bodySnapshot = $bodySnapshot; return $this; }

    /** @return array<int, array{postId: int, date: string, period: string}> */
    public function getOccurrencesSnapshot(): array { return $this->occurrencesSnapshot; }
    /** @param array<int, array{postId: int, date: string, period: string}> $occurrencesSnapshot */
    public function setOccurrencesSnapshot(array $occurrencesSnapshot): static { $this->occurrencesSnapshot = $occurrencesSnapshot; return $this; }

    public function getAbsenceDateStartSnapshot(): \DateTimeImmutable { return $this->absenceDateStartSnapshot; }
    public function setAbsenceDateStartSnapshot(\DateTimeImmutable $absenceDateStartSnapshot): static { $this->absenceDateStartSnapshot = $absenceDateStartSnapshot; return $this; }

    public function getAbsenceDateEndSnapshot(): \DateTimeImmutable { return $this->absenceDateEndSnapshot; }
    public function setAbsenceDateEndSnapshot(\DateTimeImmutable $absenceDateEndSnapshot): static { $this->absenceDateEndSnapshot = $absenceDateEndSnapshot; return $this; }

    /** @return Collection<int, SurgeonAbsenceCommunicationDelivery> */
    public function getDeliveries(): Collection { return $this->deliveries; }

    public function addDelivery(SurgeonAbsenceCommunicationDelivery $delivery): static
    {
        if (!$this->deliveries->contains($delivery)) {
            $this->deliveries->add($delivery);
            $delivery->setCommunication($this);
        }
        return $this;
    }
}
