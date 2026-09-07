<?php

namespace App\Entity;

use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Repository\ReleasedOperatingRoomSlotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * « Salles libérées » (Lot D, post D-114) — représentation métier structurée d'un créneau
 * opératoire BLOCK réellement libéré, indépendante des emails Room Release (qui restent un
 * canal d'alerte séparé, jamais la source de la vue). Alimentée par le même
 * `SurgeonAbsenceBlockOccurrenceResolver` que Room Release/Gestion du bloc — jamais un second
 * moteur de récurrence.
 *
 * Visibilité indépendante de `AbsenceCommunicationSiteConfig::notifyColleaguesEnabled` (décision
 * produit explicite) : un site avec les emails désactivés voit quand même ses créneaux libérés
 * ici — ce toggle ne contrôle que le canal email.
 *
 * `postId` est une colonne stable à part entière (identité de la contrainte d'idempotence),
 * distincte de la relation `schedulePost` qui, elle, est `ON DELETE SET NULL` (jointure de
 * confort uniquement, jamais porteuse d'identité) — même principe que
 * `SurgeonAbsenceCommunication.absence`. Une ligne n'est jamais mise à jour ni supprimée après
 * création : la règle de non-rétractation (raccourcissement/suppression d'absence) est
 * structurellement garantie par l'absence même de méthode de suppression dans le service.
 *
 * Modèle volontairement minimal pour ce lot : `assignedToSurgeon`/`assignedAt`/`closedAt`
 * n'existent pas encore, réservés au futur Lot E (« Je suis intéressé » / attribution), qui
 * les ajoutera par une migration additive dédiée le jour où ce comportement est validé.
 *
 * **Limite documentée (revue post-implémentation, 2026-09-07)** : ni le nom du site, ni le
 * nom du chirurgien, ne sont snapshotés sur cette ligne (contrairement à
 * `SurgeonAbsenceCommunication`, qui fige subject/body/dates). Si `Hospital` ou `User` est
 * supprimé plus tard (rare — pas de flux "hard delete" courant en production), l'API renvoie
 * `site: null`/`surgeon: null` et l'UI affiche un tiret « — », jamais un crash — vérifié par
 * `test_slot_survives_hospital_and_surgeon_deletion_with_no_crash`. Mais le nom au moment de
 * la publication est alors irrémédiablement perdu. Accepté pour ce lot (aucun flux de
 * suppression réelle de site/chirurgien identifié aujourd'hui) ; si cela devient un besoin
 * réel, ajouter `siteNameSnapshot`/`surgeonNameSnapshot` par une migration additive dédiée,
 * jamais en réutilisant la relation existante comme historique.
 *
 * **Contrainte unique et `site_id` nullable** : MySQL ne compare jamais deux `NULL` comme
 * égaux dans un index UNIQUE — deux lignes `(NULL, 5, '2026-01-01')` seraient donc considérées
 * distinctes et ne se bloqueraient jamais mutuellement. Sans conséquence ici : le service ne
 * crée jamais une ligne avec `site` à `NULL` (le paramètre est un `Hospital` non-nullable dans
 * `ReleasedOperatingRoomSlotService::react()`, toujours résolu depuis un `SurgeonSchedulePost`
 * réel) — `site_id` ne devient `NULL` qu'après coup, via `ON DELETE SET NULL`, jamais à
 * l'écriture. Un doublon orphelin est de plus structurellement impossible : si un `Hospital`
 * est supprimé, ses `SurgeonSchedulePost` le sont nécessairement aussi au préalable (FK
 * `RESTRICT` sur `SurgeonSchedulePost.site`), donc `SurgeonAbsenceBlockOccurrenceResolver` ne
 * peut plus jamais retrouver d'occurrence pour cet établissement disparu — aucune nouvelle
 * ligne, orpheline ou non, ne peut plus jamais être créée pour lui.
 */
#[ORM\Entity(repositoryClass: ReleasedOperatingRoomSlotRepository::class)]
#[ORM\Table(
    name: 'released_operating_room_slot',
    uniqueConstraints: [new ORM\UniqueConstraint(
        name: 'uniq_slot_site_post_occurrence',
        columns: ['site_id', 'post_id', 'occurrence_date'],
    )],
)]
#[ORM\Index(columns: ['surgeon_id'], name: 'idx_slot_surgeon')]
#[ORM\Index(columns: ['site_id', 'status', 'occurrence_date'], name: 'idx_slot_site_status_date')]
class ReleasedOperatingRoomSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    /**
     * ON DELETE SET NULL — même résilience que `surgeon`/`sourceAbsence`/`schedulePost` :
     * toujours renseigné à la création, mais un établissement supprimé plus tard ne doit
     * jamais bloquer ni effacer un créneau déjà publié (rejoint la contrainte pratique des
     * tests fonctionnels existants, qui suppriment librement leurs sites de test en fin de
     * run sans connaître cette table).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'site_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    /** Identité stable de la récurrence source — survit même si `schedulePost` est mis à NULL. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['planning:read'])]
    private int $postId;

    /** Jointure de confort uniquement — ON DELETE SET NULL, jamais porteuse d'identité. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'schedule_post_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['planning:read'])]
    private ?SurgeonSchedulePost $schedulePost = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $occurrenceDate;

    #[ORM\Column(enumType: ShiftPeriod::class, length: 12)]
    #[Groups(['planning:read'])]
    private ShiftPeriod $period;

    /** Horaires réels snapshotés depuis ShiftPeriodConfig au moment de la création — jamais inventés si non configurés. */
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $endTime = null;

    /**
     * ON DELETE SET NULL — même résilience que `sourceAbsence`/`schedulePost` : toujours
     * renseigné à la création (jamais null dans le parcours normal, "qui libère" est une
     * information métier centrale), mais un compte utilisateur supprimé plus tard (rare, ex.
     * effacement RGPD) ne doit jamais bloquer ni effacer un créneau déjà publié.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'surgeon_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['planning:read'])]
    private ?User $surgeon = null;

    /** ON DELETE SET NULL — une Absence supprimée ne doit jamais effacer ce créneau déjà publié. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_absence_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['planning:read'])]
    private ?Absence $sourceAbsence = null;

    #[ORM\Column(enumType: ReleasedRoomSlotStatus::class, length: 20)]
    #[Groups(['planning:read'])]
    private ReleasedRoomSlotStatus $status = ReleasedRoomSlotStatus::AVAILABLE;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function getPostId(): int { return $this->postId; }
    public function setPostId(int $postId): static { $this->postId = $postId; return $this; }

    public function getSchedulePost(): ?SurgeonSchedulePost { return $this->schedulePost; }
    public function setSchedulePost(?SurgeonSchedulePost $schedulePost): static { $this->schedulePost = $schedulePost; return $this; }

    public function getOccurrenceDate(): \DateTimeImmutable { return $this->occurrenceDate; }
    public function setOccurrenceDate(\DateTimeImmutable $occurrenceDate): static { $this->occurrenceDate = $occurrenceDate; return $this; }

    public function getPeriod(): ShiftPeriod { return $this->period; }
    public function setPeriod(ShiftPeriod $period): static { $this->period = $period; return $this; }

    public function getStartTime(): ?\DateTimeImmutable { return $this->startTime; }
    public function setStartTime(?\DateTimeImmutable $startTime): static { $this->startTime = $startTime; return $this; }

    public function getEndTime(): ?\DateTimeImmutable { return $this->endTime; }
    public function setEndTime(?\DateTimeImmutable $endTime): static { $this->endTime = $endTime; return $this; }

    public function getSurgeon(): ?User { return $this->surgeon; }
    public function setSurgeon(User $surgeon): static { $this->surgeon = $surgeon; return $this; }

    public function getSourceAbsence(): ?Absence { return $this->sourceAbsence; }
    public function setSourceAbsence(?Absence $sourceAbsence): static { $this->sourceAbsence = $sourceAbsence; return $this; }

    public function getStatus(): ReleasedRoomSlotStatus { return $this->status; }
    public function setStatus(ReleasedRoomSlotStatus $status): static { $this->status = $status; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
