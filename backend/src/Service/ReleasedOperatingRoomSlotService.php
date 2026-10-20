<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Repository\ReleasedOperatingRoomSlotRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * « Salles libérées » (Lot D, post D-114) — collaborateur indépendant supplémentaire, même
 * convention que `RoomReleaseCommunicationService`/`BlockManagementCommunicationService`
 * (chacun interroge ce dont il a besoin, aucun orchestrateur partagé, aucun couplage de
 * succès/échec entre canaux : un échec ici n'affecte jamais l'email Room Release, et
 * réciproquement — §23 de la demande).
 *
 * Indépendant de `AbsenceCommunicationSiteConfig::notifyColleaguesEnabled` (décision produit
 * explicite, 2026-09-06) : un créneau BLOCK réellement libéré est un fait métier, visible dans
 * SurgicalHub même si le canal email est désactivé pour ce site — ce toggle ne contrôle que
 * l'alerte email, jamais la visibilité opérationnelle.
 *
 * Réutilise `SurgeonAbsenceBlockOccurrenceResolver` (même resolver que Room Release/Gestion du
 * bloc, jamais un second moteur de récurrence) — mêmes exclusions : uniquement `BLOCK`, jamais
 * `CONSULTATION`, jamais un créneau déjà passé.
 *
 * Idempotence : une ligne par `(site, postId, occurrenceDate)`, jamais mise à jour ni
 * supprimée après création — la contrainte unique DB est le dernier garde-fou contre une
 * double création concurrente. Règle de non-rétractation : ni le raccourcissement d'une
 * absence, ni sa suppression, ne suppriment jamais un slot déjà créé — structurellement
 * garanti par l'absence délibérée de toute méthode `onAbsenceDeleted()` ici.
 *
 * `resolveFutureOccurrences()` (revue post-audit, 2026-09-07) est la source de vérité unique
 * pour « quelles occurrences BLOCK futures existent réellement pour cette absence, et
 * lesquelles ont déjà un slot ? » — réutilisée à la fois par `react()` (chemin temps réel,
 * 9ᵉ collaborateur) et par `AvailableRoomsBackfillCommand` (rattrapage basé sur les absences
 * elles-mêmes, jamais sur le journal `ROOM_RELEASE` — l'ancien `app:available-rooms:
 * backfill-from-room-release` ratait toute absence n'ayant jamais généré d'email Room
 * Release, ex. site avec `notifyColleaguesEnabled=false` à l'époque : un angle mort confirmé
 * par audit prod, absence #42, 2026-09-07).
 */
class ReleasedOperatingRoomSlotService
{
    private const SYSTEM_ACTOR_EMAIL = 'system@surgicalhub.internal';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonAbsenceBlockOccurrenceResolver $resolver,
        private readonly ReleasedOperatingRoomSlotRepository $slots,
        private readonly AuditService $audit,
        private readonly ReleasedRoomSlotTakeoverService $takeovers,
        private readonly ReleasedRoomCapacityMatcher $capacity,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onAbsenceCreated(Absence $absence, User $actor): void
    {
        $this->react($absence, $actor);
    }

    public function onAbsenceUpdated(Absence $absence, User $actor): void
    {
        // Une extension de congé révèle de nouvelles occurrences (créées ci-dessous, déjà
        // filtrées par existsFor()) ; un raccourcissement ne révèle jamais rien de nouveau et
        // ne touche jamais les lignes déjà créées (aucune suppression ici, par construction).
        $this->react($absence, $actor);
    }

    /**
     * Lecture stricte — jamais de persist/flush ici. Extrait de `react()` (revue post-audit,
     * 2026-09-07) pour être réutilisable par `AvailableRoomsBackfillCommand` (rattrapage basé
     * sur la réalité des absences, jamais sur le journal `ROOM_RELEASE`) sans jamais dupliquer
     * la logique d'écriture — même convention que `AbsenceCommunicationBackfillService`, qui
     * ne duplique que la lecture, jamais l'écriture, des services qu'il rattrape. `react()`
     * est l'unique appelant qui persiste ; le dry-run de la commande de backfill n'appelle
     * jamais que celle-ci.
     *
     * D-159 — `capacity` (null quand `alreadyExists`) : NEW = à créer ; REPLACED = la capacité
     * existe déjà sur le créneau d'un ancien poste remplacé (disponible ou déjà repris) ;
     * REVIEW = cas ambigu, jamais créé automatiquement (voir ReleasedRoomCapacityMatcher).
     *
     * @return list<array{site: Hospital, post: SurgeonSchedulePost, date: \DateTimeImmutable, period: ShiftPeriod, surgeon: User, alreadyExists: bool, capacity: ?array{decision: string, slot: ?ReleasedOperatingRoomSlot, reason: ?string}}>
     */
    public function resolveFutureOccurrences(Absence $absence): array
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $bySite = $this->resolver->resolveForWindow($surgeon, $absence->getDateStart(), $absence->getDateEnd(), $absence);
        if (empty($bySite)) {
            return [];
        }

        $result = [];

        foreach ($bySite as $siteGroup) {
            /** @var Hospital $site */
            $site = $siteGroup['site'];

            foreach ($siteGroup['occurrences'] as $occurrence) {
                /** @var SurgeonSchedulePost $post */
                $post = $occurrence['post'];
                /** @var \DateTimeImmutable $date */
                $date = $occurrence['date'];

                if ($date < $today) {
                    continue;
                }

                // D-158 — the absent surgeon's Mission for this occurrence is still active
                // (already started or encoded: never cancelled blindly) — the room is not free.
                if ($this->takeovers->findActiveOccurrenceMission($surgeon, $site, $date, $post->getPeriod()) !== null) {
                    continue;
                }

                $alreadyExists = $this->slots->existsFor($site->getId(), $post->getId(), $date);
                $result[] = [
                    'site' => $site,
                    'post' => $post,
                    'date' => $date,
                    'period' => $post->getPeriod(),
                    'surgeon' => $surgeon,
                    'alreadyExists' => $alreadyExists,
                    'capacity' => $alreadyExists ? null : $this->capacity->classify($post, $date),
                ];
            }
        }

        return $result;
    }

    private function react(Absence $absence, User $actor): void
    {
        // D-124 — l'absent peut aussi être un REPRENEUR : sa Mission de reprise vient d'être
        // annulée par AbsenceMissionReactionService (appelé avant ce collaborateur) — rendre la
        // salle plutôt que la laisser CLAIMED sur une Mission annulée.
        $this->takeovers->reopenSlotsCancelledByTakerAbsence($absence, $actor);

        foreach ($this->resolveFutureOccurrences($absence) as $occurrence) {
            if ($occurrence['alreadyExists']) {
                continue;
            }
            // D-159 — un poste remplacé n'ouvre jamais une seconde salle pour la même capacité ;
            // un cas ambigu n'est jamais tranché automatiquement.
            $capacity = $occurrence['capacity'];
            if ($capacity['decision'] === ReleasedRoomCapacityMatcher::REVIEW) {
                $this->logger->warning('Released room slot not created: ambiguous post replacement, business validation required.', [
                    'absenceId' => $absence->getId(),
                    'postId' => $occurrence['post']->getId(),
                    'occurrenceDate' => $occurrence['date']->format('Y-m-d'),
                    'reason' => $capacity['reason'],
                ]);
            }
            if ($capacity['decision'] !== ReleasedRoomCapacityMatcher::NEW) {
                continue;
            }

            $config = $this->shiftPeriodConfig($occurrence['site'], $occurrence['period']);

            try {
                // Une ligne par occurrence, insérée directement (même convention que
                // ActivityJournal) : une course concurrente sur UN (site, post, date) ne doit
                // jamais faire échouer la création des autres occurrences de cette réaction.
                // D-159 — via l'ORM, la violation de la contrainte unique fermait
                // l'EntityManager : l'occurrence suivante échouait (« EntityManager is closed »).
                $this->em->getConnection()->insert('released_operating_room_slot', [
                    'site_id' => $occurrence['site']->getId(),
                    'post_id' => $occurrence['post']->getId(),
                    'schedule_post_id' => $occurrence['post']->getId(),
                    'occurrence_date' => $occurrence['date'],
                    'period' => $occurrence['period']->value,
                    'start_time' => $config?->getStartTime(),
                    'end_time' => $config?->getEndTime(),
                    'surgeon_id' => $occurrence['surgeon']->getId(),
                    'source_absence_id' => $absence->getId(),
                    'status' => ReleasedRoomSlotStatus::AVAILABLE->value,
                    'created_at' => new \DateTimeImmutable(),
                ], [
                    'occurrence_date' => Types::DATE_IMMUTABLE,
                    'start_time' => Types::TIME_IMMUTABLE,
                    'end_time' => Types::TIME_IMMUTABLE,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                ]);
            } catch (UniqueConstraintViolationException) {
                // La ligne existe déjà (créée entretemps par une exécution concurrente) —
                // jamais une erreur pour l'appelant, même esprit que le claim atomique du
                // Lot B : la contrainte unique est le dernier garde-fou, pas le mécanisme
                // principal d'idempotence. MySQL garde la transaction utilisable.
                continue;
            }

            /** @var ReleasedOperatingRoomSlot $slot */
            $slot = $this->em->find(ReleasedOperatingRoomSlot::class, (int) $this->em->getConnection()->lastInsertId());

            // D-124 — « salle rendue disponible », uniquement pour une ligne réellement créée
            // par CETTE exécution (jamais pour un doublon concurrent détaché ci-dessus).
            // Acteur = utilisateur technique système (même convention que MissionStartDueCommand) :
            // le créneau est produit automatiquement par le moteur d'absences, pas par un geste
            // humain sur la salle ; l'humain qui a déclaré l'absence est snapshoté
            // (triggeredById/Name). Sans cela, chaque déclaration d'absence attacherait un
            // AuditEvent à son auteur (FK actor RESTRICT) — un effet de bord nouveau sur un flux
            // qui n'auditait rien jusqu'ici. Repli sur l'humain si l'utilisateur système n'existe
            // pas (base locale reconstruite sans migrations).
            $surgeon = $occurrence['surgeon'];
            $this->audit->recordGlobal($this->systemActor() ?? $actor, AuditEventType::ROOM_SLOT_MADE_AVAILABLE, [
                'roomSlotId'          => $slot->getId(),
                'postId'              => $slot->getPostId(),
                'occurrenceDate'      => $slot->getOccurrenceDate()->format('Y-m-d'),
                'period'              => $slot->getPeriod()->value,
                'startTime'           => $slot->getStartTime()?->format('H:i'),
                'endTime'             => $slot->getEndTime()?->format('H:i'),
                'siteId'              => $occurrence['site']->getId(),
                'siteName'            => $occurrence['site']->getName(),
                'originalSurgeonId'   => $surgeon->getId(),
                'originalSurgeonName' => $surgeon->getDrName(),
                'absenceId'           => $absence->getId(),
                'triggeredById'       => $actor->getId(),
                'triggeredByName'     => trim(($actor->getFirstname() ?? '') . ' ' . ($actor->getLastname() ?? '')) ?: $actor->getEmail(),
            ]);
            $this->em->flush();
        }
    }

    private function shiftPeriodConfig(Hospital $site, ShiftPeriod $period): ?ShiftPeriodConfig
    {
        $config = $this->em->getRepository(ShiftPeriodConfig::class)->findOneBy(['site' => $site, 'period' => $period]);

        return $config !== null && $config->isActive() ? $config : null;
    }

    private function systemActor(): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => self::SYSTEM_ACTOR_EMAIL]);
    }

    private static function isSurgeon(User $user): bool
    {
        return in_array('ROLE_SURGEON', $user->getRoles(), true);
    }
}
