<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\Hospital;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\ShiftPeriod;
use App\Message\SendTemplatedEmailMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

/**
 * Communication des absences chirurgiens — Lot A (D-114), §2/§3/§7/§16 de la demande.
 * « Libération de salle » : quand un chirurgien encode/allonge une absence, informe par
 * email individuel les chirurgiens collègues actifs du même site que des créneaux BLOCK se
 * libèrent — jamais pour les CONSULTATION, jamais de correction/rétractation.
 *
 * Bypass volontaire de NotificationPreferenceResolver (voir ADR D-114 dans
 * docs/decisions.md) : ce n'est pas une préférence personnelle de destinataire, mais une
 * diffusion organisationnelle gouvernée uniquement par le réglage de site
 * (AbsenceCommunicationSiteConfig::notifyColleaguesEnabled).
 */
class RoomReleaseCommunicationService
{
    private const FRENCH_DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
    private const PERIOD_LABELS = [
        ShiftPeriod::MATIN->value => 'matin',
        ShiftPeriod::APRES_MIDI->value => 'après-midi',
        ShiftPeriod::JOURNEE->value => 'journée',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonAbsenceBlockOccurrenceResolver $resolver,
        private readonly AbsenceCommunicationJournalService $journal,
        private readonly UserRepository $userRepository,
        private readonly Environment $twig,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $mailerFromName,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    public function onAbsenceCreated(Absence $absence, User $actor): void
    {
        $this->react($absence, alreadyAnnouncedFilter: false);
    }

    public function onAbsenceUpdated(Absence $absence, User $actor): void
    {
        $this->react($absence, alreadyAnnouncedFilter: true);
    }

    private function react(Absence $absence, bool $alreadyAnnouncedFilter): void
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return;
        }

        $today = new \DateTimeImmutable('today');
        $bySite = $this->resolver->resolveForWindow($surgeon, $absence->getDateStart(), $absence->getDateEnd());
        if (empty($bySite)) {
            return;
        }

        // Lot D (post D-114) — deep link vers la vue « Salles disponibles » ; simple lien
        // texte, jamais une dépendance fonctionnelle (Lot D peut être désactivé/absent sans
        // casser cet email, qui reste lisible et complet sans lui).
        $roomsUrl = rtrim($this->frontendUrl, '/') . '/app/s/planning/salles-disponibles';

        foreach ($bySite as $siteGroup) {
            /** @var Hospital $site */
            $site = $siteGroup['site'];

            $config = $this->siteConfig($site);
            if ($config === null || !$config->isNotifyColleaguesEnabled()) {
                // §15 — jamais d'envoi si la fonction est désactivée pour ce site, quelle
                // que soit l'affiliation générale des chirurgiens à ce site.
                continue;
            }

            // §16/§4 — jamais un bloc déjà passé, que ce soit à la création ou sur un
            // complément d'allongement.
            $futureOccurrences = array_values(array_filter(
                $siteGroup['occurrences'],
                static fn (array $o) => $o['date'] >= $today,
            ));
            if (empty($futureOccurrences)) {
                continue;
            }

            $snapshot = self::toSnapshot($futureOccurrences);
            $recipients = $this->userRepository->findSurgeonsAffiliatedToSite($site->getId(), excludeUserId: $surgeon->getId(), activeOnly: true);
            $subject = sprintf('Libération de salle — %s', $site->getName());

            if ($alreadyAnnouncedFilter) {
                // Revue finale Lot C (§2) — le delta "déjà annoncé" est recalculé SOUS LE
                // VERROU pessimiste de l'absence par recordRoomReleaseDelta(), jamais ici
                // (un calcul avant verrouillage permettrait à deux exécutions concurrentes de
                // ce chemin — deux managers relançant le même rattrapage, ou un rattrapage
                // concurrent d'une vraie modification — de lire toutes deux "jamais annoncé"
                // avant qu'aucune n'ait committé, puis d'annoncer deux fois les mêmes dates
                // aux mêmes collègues sous deux révisions distinctes).
                $result = $this->journal->recordRoomReleaseDelta(
                    $absence, $site, $surgeon, $snapshot, $recipients, $subject,
                    fn (array $finalSnapshot): string => $this->twig->render('emails/absence_room_release.html.twig', [
                        'siteName' => $site->getName(), 'drName' => $surgeon->getDrName(), 'roomsUrl' => $roomsUrl, 'occurrences' => self::toDisplayLines($finalSnapshot),
                    ]),
                );
                if ($result === null) {
                    // Delta vide une fois recalculé sous verrou — raccourcissement, allongement
                    // ne révélant réellement aucune nouvelle occurrence, ou déjà annoncé
                    // entretemps par une exécution concurrente (§3/§7/§2 : jamais de correction
                    // inutile, jamais un doublon).
                    continue;
                }
            } else {
                $body = $this->twig->render('emails/absence_room_release.html.twig', [
                    'siteName' => $site->getName(), 'drName' => $surgeon->getDrName(), 'roomsUrl' => $roomsUrl, 'occurrences' => self::toDisplayLines($snapshot),
                ]);
                $result = $this->journal->recordRoomRelease($absence, $site, $surgeon, $snapshot, $recipients, $subject, $body);
            }

            // Reconstruit depuis la communication réellement persistée (jamais depuis
            // `$snapshot`, qui peut différer du delta finalement retenu sous verrou) —
            // garantit que l'email dispatché correspond exactement à ce qui a été journalisé.
            $finalContext = [
                'siteName' => $site->getName(),
                'drName' => $result['communication']->getSurgeon()->getDrName(),
                'roomsUrl' => $roomsUrl,
                'occurrences' => self::toDisplayLines($result['communication']->getOccurrencesSnapshot()),
            ];

            // Dispatch strictement après le commit de la transaction du journal (même
            // discipline que partout ailleurs dans ce domaine — ex.
            // CheckUncoveredEscalationsCommand) : un email individuel et distinct par
            // collègue, jamais un envoi groupé (§16, décision actée).
            foreach ($result['deliveries'] as $delivery) {
                $this->bus->dispatch(new SendTemplatedEmailMessage(
                    to: $delivery->getRecipientEmailSnapshot(),
                    subject: $subject,
                    fromAddress: $this->mailerFromAddress,
                    fromName: $this->mailerFromName,
                    htmlTemplate: 'emails/absence_room_release.html.twig',
                    context: $finalContext,
                    absenceCommunicationDeliveryId: $delivery->getId(),
                ));
            }
        }
    }

    private function siteConfig(Hospital $site): ?AbsenceCommunicationSiteConfig
    {
        return $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
    }

    /**
     * @param array<int, array{post: SurgeonSchedulePost, date: \DateTimeImmutable}> $occurrences
     * @return array<int, array{postId: int, date: string, period: string}>
     */
    private static function toSnapshot(array $occurrences): array
    {
        return array_map(static fn (array $o) => [
            'postId' => $o['post']->getId(),
            'date' => $o['date']->format('Y-m-d'),
            'period' => $o['post']->getPeriod()->value,
        ], $occurrences);
    }

    /**
     * @param array<int, array{postId: int, date: string, period: string}> $snapshot
     * @return list<string> lignes affichées dans l'email, ex. "mercredi 02/09 — matin"
     */
    private static function toDisplayLines(array $snapshot): array
    {
        usort($snapshot, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return array_map(static function (array $o): string {
            $date = new \DateTimeImmutable($o['date']);
            $day = self::FRENCH_DAYS[(int) $date->format('N')];
            $periodLabel = self::PERIOD_LABELS[$o['period']] ?? $o['period'];
            return sprintf('%s %s — %s', $day, $date->format('d/m'), $periodLabel);
        }, $snapshot);
    }

    private static function isSurgeon(User $user): bool
    {
        return in_array('ROLE_SURGEON', $user->getRoles(), true);
    }
}
