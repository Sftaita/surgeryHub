<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\ShiftPeriod;
use App\Repository\ReleasedOperatingRoomSlotRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonAbsenceBlockOccurrenceResolver $resolver,
        private readonly ReleasedOperatingRoomSlotRepository $slots,
    ) {
    }

    public function onAbsenceCreated(Absence $absence, User $actor): void
    {
        $this->react($absence);
    }

    public function onAbsenceUpdated(Absence $absence, User $actor): void
    {
        // Une extension de congé révèle de nouvelles occurrences (créées ci-dessous, déjà
        // filtrées par existsFor()) ; un raccourcissement ne révèle jamais rien de nouveau et
        // ne touche jamais les lignes déjà créées (aucune suppression ici, par construction).
        $this->react($absence);
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
     * @return list<array{site: Hospital, post: SurgeonSchedulePost, date: \DateTimeImmutable, period: ShiftPeriod, surgeon: User, alreadyExists: bool}>
     */
    public function resolveFutureOccurrences(Absence $absence): array
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $bySite = $this->resolver->resolveForWindow($surgeon, $absence->getDateStart(), $absence->getDateEnd());
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

                $result[] = [
                    'site' => $site,
                    'post' => $post,
                    'date' => $date,
                    'period' => $post->getPeriod(),
                    'surgeon' => $surgeon,
                    'alreadyExists' => $this->slots->existsFor($site->getId(), $post->getId(), $date),
                ];
            }
        }

        return $result;
    }

    private function react(Absence $absence): void
    {
        foreach ($this->resolveFutureOccurrences($absence) as $occurrence) {
            if ($occurrence['alreadyExists']) {
                continue;
            }

            $slot = new ReleasedOperatingRoomSlot();
            $slot->setSite($occurrence['site']);
            $slot->setPostId($occurrence['post']->getId());
            $slot->setSchedulePost($occurrence['post']);
            $slot->setOccurrenceDate($occurrence['date']);
            $slot->setPeriod($occurrence['period']);
            $slot->setSurgeon($occurrence['surgeon']);
            $slot->setSourceAbsence($absence);

            $config = $this->shiftPeriodConfig($occurrence['site'], $occurrence['period']);
            if ($config !== null) {
                $slot->setStartTime($config->getStartTime());
                $slot->setEndTime($config->getEndTime());
            }

            $this->em->persist($slot);

            try {
                // Un flush par slot, jamais un seul flush groupé en fin de boucle : une
                // course concurrente sur UN (site, post, date) ne doit jamais faire échouer
                // la création des autres occurrences de cette même réaction.
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // La ligne existe déjà (créée entretemps par une exécution concurrente) —
                // jamais une erreur pour l'appelant, même esprit que le claim atomique du
                // Lot B : la contrainte unique est le dernier garde-fou, pas le mécanisme
                // principal d'idempotence.
                $this->em->detach($slot);
            }
        }
    }

    private function shiftPeriodConfig(Hospital $site, ShiftPeriod $period): ?ShiftPeriodConfig
    {
        $config = $this->em->getRepository(ShiftPeriodConfig::class)->findOneBy(['site' => $site, 'period' => $period]);

        return $config !== null && $config->isActive() ? $config : null;
    }

    private static function isSurgeon(User $user): bool
    {
        return in_array('ROLE_SURGEON', $user->getRoles(), true);
    }
}
