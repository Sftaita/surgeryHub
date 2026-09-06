<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\User;
use App\Enum\AbsenceBackfillBlockManagementAction;
use App\Enum\AbsenceBackfillRoomReleaseAction;
use App\Enum\AbsenceCommunicationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Communication des absences chirurgiens — Lot C (D-114). Rattrapage des absences déjà
 * existantes au moment où les Lots A/B ont été déployés : filtre strict sur
 * `Absence.createdAt` (jamais `dateStart`/`dateEnd`/une date de BLOCK/une date d'envoi —
 * §2), preview obligatoire en lecture pure, exécution qui réutilise intégralement
 * `RoomReleaseCommunicationService`/`BlockManagementCommunicationService` — ce service ne
 * duplique jamais leur logique de décision réelle, il l'appelle (§3, §11 : « rester fin »).
 *
 * `preview()` et l'exécution partagent la même classification en lecture
 * (`classifyRoomRelease()`/`classifyBlockManagement()`), qui rejoue fidèlement les mêmes
 * conditions que les deux services réels sans jamais rien écrire — c'est délibérément une
 * DUPLICATION DE LECTURE de leur logique de décision (jamais de leur logique d'écriture),
 * seule façon d'offrir une preview strictement sans effet de bord tout en gardant les deux
 * chemins alignés. Toute dérive future entre cette classification et le comportement réel
 * des deux services serait un bug — voir les tests qui les exercent côte à côte.
 *
 * Idempotence : aucune nouvelle couche — `onAbsenceUpdated()` des deux services est déjà
 * idempotent par construction (delta d'occurrences jamais annoncées pour Room Release,
 * find-or-create + comparaison de snapshot pour Gestion du bloc), donc rejouer ce rattrapage
 * plusieurs fois sur la même absence ne crée jamais de doublon — exactement le même
 * mécanisme qu'un vrai `PATCH` sans changement réel.
 */
class AbsenceCommunicationBackfillService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonAbsenceBlockOccurrenceResolver $resolver,
        private readonly AbsenceCommunicationJournalService $journal,
        private readonly UserRepository $userRepository,
        private readonly RoomReleaseCommunicationService $roomReleaseCommunicationService,
        private readonly BlockManagementCommunicationService $blockManagementCommunicationService,
    ) {
    }

    /**
     * Lecture pure — aucun journal créé, aucune delivery créée, aucun email, aucun statut
     * modifié, aucune programmation (§5 de la demande).
     *
     * @return array{
     *   createdFrom: string,
     *   summary: array<string, int>,
     *   items: list<array<string, mixed>>,
     * }
     */
    public function preview(\DateTimeImmutable $createdFrom): array
    {
        $totalAbsences = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')->from(Absence::class, 'a')
            ->getQuery()->getSingleScalarResult();

        $eligibleAbsences = $this->eligibleAbsences($createdFrom);
        $ignoredOlderAbsences = $totalAbsences - count($eligibleAbsences);

        $items = [];
        $summary = [
            'totalAbsencesAnalyzed' => $totalAbsences,
            'ignoredOlderAbsences' => $ignoredOlderAbsences,
            'eligibleAbsences' => count($eligibleAbsences),
            'noActionAbsences' => 0,
            'roomReleaseEmailsPotential' => 0,
            'blockManagementImmediate' => 0,
            'blockManagementScheduled' => 0,
            'alreadyProcessedSites' => 0,
        ];

        foreach ($eligibleAbsences as $absence) {
            $item = $this->classifyAbsence($absence);
            $items[] = $item;

            if (!$item['selectable']) {
                $summary['noActionAbsences']++;
            }
            foreach ($item['sites'] as $siteRow) {
                if ($siteRow['roomRelease']['status'] === AbsenceBackfillRoomReleaseAction::WILL_SEND->value) {
                    $summary['roomReleaseEmailsPotential']++;
                }
                if ($siteRow['blockManagement']['status'] === AbsenceBackfillBlockManagementAction::WILL_SEND_NOW->value) {
                    $summary['blockManagementImmediate']++;
                }
                if ($siteRow['blockManagement']['status'] === AbsenceBackfillBlockManagementAction::WILL_SCHEDULE->value) {
                    $summary['blockManagementScheduled']++;
                }
                if ($siteRow['blockManagement']['status'] === AbsenceBackfillBlockManagementAction::ALREADY_PROCESSED->value) {
                    $summary['alreadyProcessedSites']++;
                }
            }
        }

        return [
            'createdFrom' => $createdFrom->format('Y-m-d'),
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Revalide TOUT côté serveur (§9 : jamais confiance aveugle dans une preview passée) —
     * ne relit jamais le résultat d'une preview précédente, ne prend en paramètre que le
     * cutoff et la sélection d'IDs d'absence. Chaque absence est traitée indépendamment
     * (try/catch par absence, §26) : un échec isolé n'affecte jamais les absences déjà
     * traitées avec succès dans la même exécution.
     *
     * Sélection au grain de l'ABSENCE COMPLÈTE, jamais par (absence, site) — voir le rapport
     * final pour la justification détaillée (§8) : `onAbsenceUpdated()` des deux services
     * traite déjà tous les sites concernés d'une absence en une fois, avec son propre
     * filtrage par site (config activée, occurrences, etc.) déjà correct et déjà testé ;
     * reproduire un filtrage par site ici dupliquerait exactement la logique que ce service
     * doit au contraire réutiliser telle quelle.
     *
     * @param list<int> $absenceIds
     * @return array{createdFrom: string, results: list<array<string, mixed>>}
     */
    public function execute(\DateTimeImmutable $createdFrom, array $absenceIds, User $actor): array
    {
        $results = [];

        foreach (array_unique($absenceIds) as $absenceId) {
            try {
                $results[] = $this->executeOne($createdFrom, $absenceId, $actor);
            } catch (\Throwable $e) {
                $results[] = [
                    'absenceId' => $absenceId,
                    'status' => 'ERROR',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'createdFrom' => $createdFrom->format('Y-m-d'),
            'results' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function executeOne(\DateTimeImmutable $createdFrom, int $absenceId, User $actor): array
    {
        $absence = $this->em->find(Absence::class, $absenceId);
        if ($absence === null) {
            // §13 — l'absence a disparu entre preview et execute : jamais recréée depuis un
            // snapshot de preview, simplement ignorée.
            return ['absenceId' => $absenceId, 'status' => 'SKIPPED_NOT_FOUND'];
        }

        // Re-validation complète de l'éligibilité (§9) — le cutoff est immuable une fois
        // l'absence créée (createdAt ne change jamais), mais le rôle du chirurgien pourrait
        // théoriquement avoir changé entretemps ; jamais une confiance aveugle.
        if ($absence->getCreatedAt() === null || $absence->getCreatedAt() < $createdFrom) {
            return ['absenceId' => $absenceId, 'status' => 'SKIPPED_BEFORE_CUTOFF'];
        }
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return ['absenceId' => $absenceId, 'status' => 'SKIPPED_NOT_A_SURGEON'];
        }

        $before = $this->snapshotCommunicationIds($absence);

        // Room Release : `onAbsenceUpdated()` est intrinsèquement delta-aware (n'annonce
        // jamais deux fois la même occurrence) — strictement identique à un vrai PATCH sans
        // changement de dates, donc naturellement idempotent sur plusieurs exécutions du
        // rattrapage (§11 : aucune seconde couche d'idempotence nécessaire).
        $this->roomReleaseCommunicationService->onAbsenceUpdated($absence, $actor);

        // Gestion du bloc : §14 — jamais de notification rétroactive pour un congé déjà
        // entièrement terminé. `onAbsenceUpdated()` avec les dates courantes comme
        // "précédentes" reproduit exactement le chemin `onAbsenceCreated()` pour tout site
        // encore sans communication (find-or-create déjà idempotent), et laisse un site déjà
        // traité intact (`ALREADY_PROCESSED`, jamais une seconde communication ni une
        // modification artificielle — §3.B).
        $blockManagementSkippedEnded = $absence->getDateEnd() < new \DateTimeImmutable('today');
        if (!$blockManagementSkippedEnded) {
            $this->blockManagementCommunicationService->onAbsenceUpdated(
                $absence, $actor, $absence->getDateStart(), $absence->getDateEnd(),
            );
        }

        $after = $this->snapshotCommunicationIds($absence);
        $newCommunicationIds = array_diff($after, $before);

        return [
            'absenceId' => $absenceId,
            'status' => 'PROCESSED',
            'blockManagementSkippedReason' => $blockManagementSkippedEnded ? 'ABSENCE_ALREADY_ENDED' : null,
            'newCommunicationCount' => count($newCommunicationIds),
        ];
    }

    /** @return list<int> */
    private function snapshotCommunicationIds(Absence $absence): array
    {
        return array_map(
            static fn (SurgeonAbsenceCommunication $c) => $c->getId(),
            $this->em->createQueryBuilder()
                ->select('c')->from(SurgeonAbsenceCommunication::class, 'c')
                ->where('c.absence = :absence')->setParameter('absence', $absence)
                ->getQuery()->getResult(),
        );
    }

    /** @return list<Absence> */
    private function eligibleAbsences(\DateTimeImmutable $createdFrom): array
    {
        return $this->em->createQueryBuilder()
            ->select('a', 'u')
            ->from(Absence::class, 'a')
            ->join('a.user', 'u')
            ->where('a.createdAt >= :createdFrom')
            ->orderBy('a.createdAt', 'ASC')
            ->setParameter('createdFrom', $createdFrom)
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, mixed> */
    private function classifyAbsence(Absence $absence): array
    {
        $surgeon = $absence->getUser();
        $sites = [];
        $selectable = false;

        if ($surgeon !== null && self::isSurgeon($surgeon)) {
            $bySite = $this->resolver->resolveForWindow($surgeon, $absence->getDateStart(), $absence->getDateEnd());
            $today = new \DateTimeImmutable('today');

            foreach ($bySite as $siteGroup) {
                /** @var Hospital $site */
                $site = $siteGroup['site'];
                $futureOccurrences = array_values(array_filter(
                    $siteGroup['occurrences'],
                    static fn (array $o) => $o['date'] >= $today,
                ));

                $roomRelease = $this->classifyRoomRelease($absence, $surgeon, $site, $futureOccurrences);
                $blockManagement = $this->classifyBlockManagement($absence, $site);

                if ($roomRelease['status'] === AbsenceBackfillRoomReleaseAction::WILL_SEND->value
                    || in_array($blockManagement['status'], [
                        AbsenceBackfillBlockManagementAction::WILL_SEND_NOW->value,
                        AbsenceBackfillBlockManagementAction::WILL_SCHEDULE->value,
                    ], true)
                ) {
                    $selectable = true;
                }

                $sites[] = [
                    'siteId' => $site->getId(),
                    'siteName' => $site->getName(),
                    'futureBlockOccurrenceCount' => count($futureOccurrences),
                    'roomRelease' => $roomRelease,
                    'blockManagement' => $blockManagement,
                ];
            }
        }

        return [
            'absenceId' => $absence->getId(),
            'surgeonId' => $surgeon?->getId(),
            'surgeonName' => $surgeon !== null ? self::displayName($surgeon) : null,
            'createdAt' => $absence->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'dateStart' => $absence->getDateStart()->format('Y-m-d'),
            'dateEnd' => $absence->getDateEnd()->format('Y-m-d'),
            'selectable' => $selectable,
            'sites' => $sites,
        ];
    }

    /**
     * Rejoue en LECTURE SEULE exactement les conditions de
     * `RoomReleaseCommunicationService::react()` — jamais sa logique d'écriture.
     *
     * @param array<int, array{post: \App\Entity\SurgeonSchedulePost, date: \DateTimeImmutable}> $futureOccurrences
     * @return array{status: string, recipientCount: int, newOccurrenceCount: int}
     */
    private function classifyRoomRelease(Absence $absence, User $surgeon, Hospital $site, array $futureOccurrences): array
    {
        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        if ($config === null || !$config->isNotifyColleaguesEnabled()) {
            return ['status' => AbsenceBackfillRoomReleaseAction::DISABLED->value, 'recipientCount' => 0, 'newOccurrenceCount' => 0];
        }

        if (empty($futureOccurrences)) {
            return ['status' => AbsenceBackfillRoomReleaseAction::NO_FUTURE_BLOCK->value, 'recipientCount' => 0, 'newOccurrenceCount' => 0];
        }

        $alreadyAnnounced = $this->journal->alreadyAnnouncedOccurrenceKeys($absence, $site);
        $delta = array_values(array_filter(
            $futureOccurrences,
            static fn (array $o) => !isset($alreadyAnnounced[AbsenceCommunicationJournalService::occurrenceKey(
                $o['post']->getId(), $o['date']->format('Y-m-d'),
            )]),
        ));
        if (empty($delta)) {
            return ['status' => AbsenceBackfillRoomReleaseAction::NO_NEW_OCCURRENCE->value, 'recipientCount' => 0, 'newOccurrenceCount' => 0];
        }

        $recipients = $this->userRepository->findSurgeonsAffiliatedToSite($site->getId(), excludeUserId: $surgeon->getId(), activeOnly: true);
        if (empty($recipients)) {
            return ['status' => AbsenceBackfillRoomReleaseAction::NO_RECIPIENT->value, 'recipientCount' => 0, 'newOccurrenceCount' => count($delta)];
        }

        return ['status' => AbsenceBackfillRoomReleaseAction::WILL_SEND->value, 'recipientCount' => count($recipients), 'newOccurrenceCount' => count($delta)];
    }

    /**
     * Rejoue en LECTURE SEULE exactement les conditions de la branche "neverSent" de
     * `BlockManagementCommunicationService::react()` — jamais sa logique d'écriture.
     *
     * @return array{status: string, scheduledAt: ?string}
     */
    private function classifyBlockManagement(Absence $absence, Hospital $site): array
    {
        // §14 — un congé déjà entièrement terminé au moment du rattrapage ne déclenche
        // jamais de notification rétroactive de gestion du bloc.
        if ($absence->getDateEnd() < new \DateTimeImmutable('today')) {
            return ['status' => AbsenceBackfillBlockManagementAction::ABSENCE_ALREADY_ENDED->value, 'scheduledAt' => null];
        }

        // Revue finale Lot C (§13/§14) — reproduit EXACTEMENT le `$neverSent` de
        // BlockManagementCommunicationService::react() : une communication existante dont la
        // seule delivery est encore SCHEDULED ou a été CANCELLED avant tout envoi réel n'a
        // jamais réellement communiqué quoi que ce soit — l'exécution doit alors pouvoir
        // (re)traiter ce site, jamais le classer ALREADY_PROCESSED à tort (ce qui préviendrait
        // toute divergence entre ce que la preview annonce et ce que execute() fait réellement).
        $existing = $this->journal->blockManagementCommunicationsBySite($absence)[$site->getId()] ?? [];
        $latest = $existing === [] ? null : end($existing);
        $latestDelivery = $latest?->getDeliveries()->first();
        $neverSent = $latestDelivery === null || in_array($latestDelivery->getStatus(), [AbsenceCommunicationStatus::SCHEDULED, AbsenceCommunicationStatus::CANCELLED], true);
        if (!$neverSent) {
            // §3.B — une communication a déjà été réellement envoyée (ou une tentative a
            // échoué) pour ce site : jamais une seconde communication initiale, jamais une
            // modification artificielle depuis le rattrapage.
            return ['status' => AbsenceBackfillBlockManagementAction::ALREADY_PROCESSED->value, 'scheduledAt' => null];
        }

        $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
        if ($config === null || !$config->isNotifyBlockManagementEnabled()) {
            return ['status' => AbsenceBackfillBlockManagementAction::DISABLED->value, 'scheduledAt' => null];
        }

        $to = trim((string) $config->getBlockManagementEmailTo());
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false || $config->getBlockManagementDelayDays() === null) {
            return ['status' => AbsenceBackfillBlockManagementAction::MISSING_CONFIG->value, 'scheduledAt' => null];
        }

        $scheduledAt = $absence->getDateStart()->modify(sprintf('-%d days', $config->getBlockManagementDelayDays()));
        if ($scheduledAt <= new \DateTimeImmutable()) {
            return ['status' => AbsenceBackfillBlockManagementAction::WILL_SEND_NOW->value, 'scheduledAt' => $scheduledAt->format(\DateTimeInterface::ATOM)];
        }

        return ['status' => AbsenceBackfillBlockManagementAction::WILL_SCHEDULE->value, 'scheduledAt' => $scheduledAt->format(\DateTimeInterface::ATOM)];
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }

    private static function isSurgeon(User $user): bool
    {
        return in_array('ROLE_SURGEON', $user->getRoles(), true);
    }
}
