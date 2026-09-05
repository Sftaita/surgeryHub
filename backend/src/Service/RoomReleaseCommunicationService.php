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

            if ($alreadyAnnouncedFilter) {
                $alreadyAnnounced = $this->journal->alreadyAnnouncedOccurrenceKeys($absence, $site);
                $snapshot = array_values(array_filter(
                    $snapshot,
                    static fn (array $o) => !isset($alreadyAnnounced[AbsenceCommunicationJournalService::occurrenceKey($o['postId'], $o['date'])]),
                ));
                if (empty($snapshot)) {
                    // Raccourcissement, ou allongement ne révélant aucune nouvelle
                    // occurrence — aucun complément (§3/§7 : jamais de correction inutile).
                    continue;
                }
            }

            $recipients = $this->userRepository->findSurgeonsAffiliatedToSite($site->getId(), excludeUserId: $surgeon->getId(), activeOnly: true);

            $subject = sprintf('Libération de salle — %s', $site->getName());
            $context = ['siteName' => $site->getName(), 'occurrences' => self::toDisplayLines($snapshot)];
            $body = $this->twig->render('emails/absence_room_release.html.twig', $context);

            $result = $this->journal->recordRoomRelease($absence, $site, $surgeon, $snapshot, $recipients, $subject, $body);

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
                    context: $context,
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
