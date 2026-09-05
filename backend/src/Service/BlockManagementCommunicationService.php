<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\AbsenceCommunicationSiteConfig;
use App\Entity\SurgeonAbsenceCommunication;
use App\Entity\SurgeonAbsenceCommunicationDelivery;
use App\Entity\User;
use App\Enum\AbsenceCommunicationStatus;
use App\Enum\AbsenceCommunicationType;
use App\Message\SendTemplatedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Communication des absences chirurgiens — Lot B (D-114). « Gestion du bloc » : informe une
 * adresse mailbox configurée par site (pas un `User` SurgicalHub) qu'un chirurgien pose,
 * modifie ou supprime un congé, avec un délai configurable avant le début du congé.
 *
 * Collaborateur indépendant, même style que les 7 autres de ce domaine (chacun interroge ce
 * dont il a besoin — aucun orchestrateur partagé). Un site n'est concerné que s'il porte au
 * moins une occurrence théorique `BLOCK` du chirurgien dans la fenêtre de l'absence (même
 * règle que `RoomReleaseCommunicationService`, réutilise le même resolver) — jamais une
 * simple affiliation générale.
 *
 * Contrairement à `ROOM_RELEASE` (toujours immuable dès la création), une communication
 * `BLOCK_MANAGEMENT_ABSENCE` encore `SCHEDULED`/`CANCELLED`-avant-envoi est **mutable en
 * place** : rien n'a encore été communiqué, donc recalculer `scheduledAt`, annuler ou
 * réactiver la même ligne est sûr. Une fois `SENT`, elle devient immuable ; tout changement
 * ultérieur (dates modifiées, ou le site cessant d'être concerné après avoir déjà été
 * prévenu — voir `react()`) crée une nouvelle communication `BLOCK_MANAGEMENT_MODIFICATION`
 * (revision propre) : contrairement à `ROOM_RELEASE`, un site déjà destinataire de la
 * gestion du bloc doit toujours refléter l'état réel du congé, jamais une correction
 * silencieuse ni une rétractation muette.
 *
 * Bypass volontaire de `NotificationPreferenceResolver`, même raisonnement qu'en Lot A (ADR
 * D-114) — diffusion organisationnelle par site, destinataire principal qui n'est même pas
 * un `User`.
 */
class BlockManagementCommunicationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SurgeonAbsenceBlockOccurrenceResolver $resolver,
        private readonly AbsenceCommunicationJournalService $journal,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $mailerFromName,
    ) {
    }

    public function onAbsenceCreated(Absence $absence, User $actor): void
    {
        $this->react($absence, previousDateStart: null, previousDateEnd: null);
    }

    public function onAbsenceUpdated(Absence $absence, User $actor, \DateTimeImmutable $previousDateStart, \DateTimeImmutable $previousDateEnd): void
    {
        $this->react($absence, $previousDateStart, $previousDateEnd);
    }

    /**
     * @return array{blockManagementAlreadyNotified: bool, sites: array<int, array{siteId: int, siteName: ?string, notificationSentAt: ?string}>}
     */
    public function deletionInfo(Absence $absence): array
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return ['blockManagementAlreadyNotified' => false, 'sites' => []];
        }

        $sites = [];
        foreach ($this->journal->blockManagementCommunicationsBySite($absence) as $siteId => $communications) {
            $latest = end($communications);
            $delivery = $latest->getDeliveries()->first();
            if ($delivery instanceof SurgeonAbsenceCommunicationDelivery && $delivery->getStatus() === AbsenceCommunicationStatus::SENT) {
                $sites[] = [
                    'siteId' => $siteId,
                    'siteName' => $latest->getSite()?->getName(),
                    'notificationSentAt' => $delivery->getSentAt()?->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return ['blockManagementAlreadyNotified' => $sites !== [], 'sites' => $sites];
    }

    /**
     * Appelé AVANT `$em->remove($absence)` (même discipline que les collaborateurs
     * existants d'`AbsenceController::delete()`) — a besoin de l'absence encore vivante
     * pour retrouver ses communications et construire le contenu de l'email d'annulation.
     *
     * `$notifyCancellation` vient du choix explicite de l'utilisateur (§18-22 de la
     * demande) — jamais déduit ici. Un site encore `SCHEDULED` est toujours annulé
     * silencieusement, quelle que soit cette valeur (§17) ; elle ne gouverne que les sites
     * déjà `SENT`.
     */
    public function onAbsenceDeleted(Absence $absence, User $actor, bool $notifyCancellation): void
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return;
        }

        foreach ($this->journal->blockManagementCommunicationsBySite($absence) as $communications) {
            $latest = end($communications);
            $site = $latest->getSite();
            $delivery = $latest->getDeliveries()->first();
            if (!$delivery instanceof SurgeonAbsenceCommunicationDelivery || $site === null) {
                continue;
            }

            if ($delivery->getStatus() === AbsenceCommunicationStatus::SCHEDULED) {
                $this->journal->cancelPendingDelivery($delivery, "Absence supprimée avant l'échéance programmée.");
                continue;
            }

            if ($delivery->getStatus() === AbsenceCommunicationStatus::SENT && $notifyCancellation) {
                $subject = self::subjectFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION, $surgeon);
                $body = self::renderCancellationBody($absence, $surgeon);
                // Revue finale (§12) — dedupeIfExists: une annulation est un événement binaire
                // par (absence, site), jamais une valeur à comparer comme les dates d'une
                // modification — deux requêtes DELETE concurrentes/retry pour la même absence
                // ne doivent jamais produire deux emails d'annulation pour le même site.
                $result = $this->journal->recordBlockManagementFollowUp(
                    $absence, $site, $surgeon, AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION, $subject, $body,
                    dedupeIfExists: true,
                );
                if ($result['alreadyExisted']) {
                    continue;
                }
                $this->dispatchNow(
                    $result['delivery'], $surgeon, $subject,
                    self::templateFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION),
                    self::contextFor($result['communication']),
                );
            }
        }
    }

    private function react(Absence $absence, ?\DateTimeImmutable $previousDateStart, ?\DateTimeImmutable $previousDateEnd): void
    {
        $surgeon = $absence->getUser();
        if ($surgeon === null || !self::isSurgeon($surgeon)) {
            return;
        }

        $isUpdate = $previousDateStart !== null;
        $existingBySite = $isUpdate ? $this->journal->blockManagementCommunicationsBySite($absence) : [];

        $bySite = $this->resolver->resolveForWindow($surgeon, $absence->getDateStart(), $absence->getDateEnd());
        // Gestion du bloc : la période complète du congé est communiquée, pas seulement les
        // dates futures — contrairement à ROOM_RELEASE, il n'y a pas de notion de "créneau
        // déjà passé" à exclure ici (§9 : on informe d'un congé, pas d'un créneau libéré).
        $concernedSites = [];
        foreach ($bySite as $siteId => $group) {
            $concernedSites[$siteId] = $group['site'];
        }

        // Sites qui avaient une communication mais ne sont plus concernés : annuler
        // silencieusement si encore SCHEDULED (§14 cas B — rien n'a encore été communiqué,
        // il n'y a rien à corriger). Un site déjà SENT/FAILED (une tentative réelle a eu
        // lieu) reste en revanche destinataire légitime d'une mise à jour : contrairement à
        // ROOM_RELEASE (jamais de correction/rétractation), la gestion du bloc doit tenir
        // informé tout site déjà prévenu de l'évolution réelle du congé, même s'il ne
        // couvre plus aucun BLOCK théorique dans la nouvelle période (§5 de la revue
        // finale — décision actée : envoyer une BLOCK_MANAGEMENT_MODIFICATION avec la
        // nouvelle période globale, jamais un message inventé du type "votre site n'est
        // plus concerné"). Idempotent via la même comparaison de dates que la branche
        // "toujours concerné" ci-dessous : un site déjà mis à jour pour cette période
        // exacte ne reçoit jamais de second email.
        foreach ($existingBySite as $siteId => $communications) {
            if (isset($concernedSites[$siteId])) {
                continue;
            }
            $latest = end($communications);
            $site = $latest->getSite();
            $delivery = $latest->getDeliveries()->first();
            if (!$delivery instanceof SurgeonAbsenceCommunicationDelivery || $site === null) {
                continue;
            }

            if ($delivery->getStatus() === AbsenceCommunicationStatus::SCHEDULED) {
                $this->journal->cancelPendingDelivery($delivery, "Site plus concerné après modification du congé (aucun BLOCK dans la nouvelle période).");
                continue;
            }
            if ($delivery->getStatus() === AbsenceCommunicationStatus::CANCELLED) {
                continue;
            }

            // SENT ou FAILED — déjà destinataire d'une tentative réelle.
            $datesChanged = $latest->getAbsenceDateStartSnapshot()->format('Y-m-d') !== $absence->getDateStart()->format('Y-m-d')
                || $latest->getAbsenceDateEndSnapshot()->format('Y-m-d') !== $absence->getDateEnd()->format('Y-m-d');
            if (!$datesChanged) {
                continue;
            }

            $subject = self::subjectFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION, $surgeon);
            $body = self::renderModificationBody($absence, $surgeon);
            $result = $this->journal->recordBlockManagementFollowUp(
                $absence, $site, $surgeon, AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION, $subject, $body,
            );
            $this->dispatchNow(
                $result['delivery'], $surgeon, $subject,
                self::templateFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION),
                self::contextFor($result['communication']),
            );
        }

        foreach ($concernedSites as $siteId => $site) {
            $config = $this->em->getRepository(AbsenceCommunicationSiteConfig::class)->findOneBy(['site' => $site]);
            $existing = $existingBySite[$siteId] ?? [];
            $latest = $existing === [] ? null : end($existing);
            $latestDelivery = $latest?->getDeliveries()->first();

            if ($config === null || !$config->isNotifyBlockManagementEnabled()) {
                // §13 — OFF ou jamais configuré : no-op pour un site nouveau. Pour un site
                // déjà porteur d'une communication encore SCHEDULED (activé puis désactivé
                // entretemps) : annuler ce préavis pas-encore-envoyé, jamais le forcer.
                if ($latestDelivery instanceof SurgeonAbsenceCommunicationDelivery && $latestDelivery->getStatus() === AbsenceCommunicationStatus::SCHEDULED) {
                    $this->journal->cancelPendingDelivery($latestDelivery, 'Gestion du bloc désactivée pour ce site.');
                }
                continue;
            }

            $neverSent = $latestDelivery === null || in_array($latestDelivery->getStatus(), [AbsenceCommunicationStatus::SCHEDULED, AbsenceCommunicationStatus::CANCELLED], true);

            if ($neverSent) {
                $scheduledAt = $absence->getDateStart()->modify(sprintf('-%d days', $config->getBlockManagementDelayDays() ?? 0));
                $subject = self::subjectFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE, $surgeon);
                $body = self::renderAbsenceBody($absence, $surgeon);
                $result = $this->journal->upsertPendingBlockManagementNotice($absence, $site, $surgeon, $subject, $body, $scheduledAt);

                if (!$result['alreadyFinal'] && $scheduledAt <= new \DateTimeImmutable()) {
                    $this->dispatchNow(
                        $result['delivery'], $surgeon, $subject,
                        self::templateFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE),
                        self::contextFor($result['communication']),
                    );
                }
                continue;
            }

            // Déjà SENT (ou FAILED, traité de la même façon : une tentative a déjà eu lieu
            // pour ce congé) — un email de modification seulement si les dates ont
            // réellement changé (§16 : jamais de correction inutile).
            $datesChanged = $latest->getAbsenceDateStartSnapshot()->format('Y-m-d') !== $absence->getDateStart()->format('Y-m-d')
                || $latest->getAbsenceDateEndSnapshot()->format('Y-m-d') !== $absence->getDateEnd()->format('Y-m-d');
            if (!$datesChanged) {
                continue;
            }

            $subject = self::subjectFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION, $surgeon);
            $body = self::renderModificationBody($absence, $surgeon);
            $result = $this->journal->recordBlockManagementFollowUp(
                $absence, $site, $surgeon, AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION, $subject, $body,
            );
            $this->dispatchNow(
                $result['delivery'], $surgeon, $subject,
                self::templateFor(AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION),
                self::contextFor($result['communication']),
            );
        }
    }

    /**
     * Dispatch immédiat (création/modification/annulation) — réutilise les valeurs déjà
     * résolues "live" par le journal au moment de la création de cette communication (même
     * requête, quelques millisecondes d'écart, pas besoin de résoudre une seconde fois).
     * Contrairement au cron (résolution live juste avant l'échéance, potentiellement des
     * jours plus tard), une résolution répétée ici serait sans effet observable.
     *
     * `recipientEmailSnapshot` vide signifie une configuration invalide au moment de la
     * résolution (§13 : ne jamais inventer un fallback) — marque `FAILED` immédiatement
     * plutôt que de dispatcher un email sans destinataire.
     */
    private function dispatchNow(SurgeonAbsenceCommunicationDelivery $delivery, User $surgeon, string $subject, string $htmlTemplate, array $context): void
    {
        if ($delivery->getRecipientEmailSnapshot() === '') {
            $this->journal->recordDeliveryFailure(
                $delivery->getId(),
                'Adresse de gestion du bloc manquante ou invalide au moment de l\'envoi.',
                final: true,
            );
            return;
        }

        $this->journal->markDispatchClaimed($delivery->getId());

        try {
            $this->bus->dispatch(new SendTemplatedEmailMessage(
                to: $delivery->getRecipientEmailSnapshot(),
                subject: $subject,
                fromAddress: $this->mailerFromAddress,
                fromName: $this->mailerFromName,
                htmlTemplate: $htmlTemplate,
                context: $context,
                absenceCommunicationDeliveryId: $delivery->getId(),
                cc: $delivery->getRecipientCcSnapshot(),
                replyTo: $surgeon->getEmail(),
            ));
        } catch (\Throwable $e) {
            // Revue finale (§1) — le dispatch Messenger lui-même a échoué (jamais un échec
            // SMTP ultérieur dans le worker, qui reste couvert par recordDeliveryFailure()) :
            // libérer le claim pour qu'un appel ultérieur (nouvelle création/modification, ou
            // le cron si scheduledAt est déjà passé) puisse retenter, plutôt que de laisser la
            // ligne bloquée indéfiniment avec un dispatchClaimedAt qui ne correspond à rien de
            // réellement confié au pipeline.
            $this->journal->releaseDispatchClaim($delivery->getId(), $e->getMessage());
            throw $e;
        }
    }

    /**
     * Réutilisé par SendScheduledAbsenceCommunicationsCommand pour reconstruire le
     * template/contexte d'une communication déjà persistée (le cron ne connaît qu'un id de
     * delivery, pas le contexte d'origine — tout est reconstruit depuis les snapshots du
     * parent, jamais dupliqué).
     */
    public static function templateFor(AbsenceCommunicationType $type): string
    {
        return match ($type) {
            AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE => 'emails/absence_block_management.html.twig',
            AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION => 'emails/absence_block_management_modification.html.twig',
            AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION => 'emails/absence_block_management_cancellation.html.twig',
            default => throw new \LogicException('Type non supporté par BlockManagementCommunicationService: ' . $type->value),
        };
    }

    /** @return array{drName: string, dateStart: string, dateEnd: string} */
    public static function contextFor(SurgeonAbsenceCommunication $communication): array
    {
        return [
            'drName' => self::drName($communication->getSurgeon()),
            'dateStart' => $communication->getAbsenceDateStartSnapshot()->format('d/m/Y'),
            'dateEnd' => $communication->getAbsenceDateEndSnapshot()->format('d/m/Y'),
        ];
    }

    private static function subjectFor(AbsenceCommunicationType $type, User $surgeon): string
    {
        $name = self::drName($surgeon);

        return match ($type) {
            AbsenceCommunicationType::BLOCK_MANAGEMENT_ABSENCE => sprintf('Congé — %s', $name),
            AbsenceCommunicationType::BLOCK_MANAGEMENT_MODIFICATION => sprintf('Modification de congé — %s', $name),
            AbsenceCommunicationType::BLOCK_MANAGEMENT_CANCELLATION => sprintf('Annulation de congé — %s', $name),
            default => throw new \LogicException('Type non supporté par BlockManagementCommunicationService: ' . $type->value),
        };
    }

    private static function renderAbsenceBody(Absence $absence, User $surgeon): string
    {
        return sprintf(
            "Bonjour,\n\nJe vous informe que je serai en congé du %s au %s inclus.\n\nMerci d'en prendre note pour l'organisation du bloc opératoire.\n\nBien à vous,\n%s",
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
            self::drName($surgeon),
        );
    }

    private static function renderModificationBody(Absence $absence, User $surgeon): string
    {
        return sprintf(
            "Bonjour,\n\nJe vous informe d'une modification concernant la période de congé communiquée précédemment.\n\nJe serai finalement en congé du %s au %s inclus.\n\nMerci d'en prendre note.\n\nBien à vous,\n%s",
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
            self::drName($surgeon),
        );
    }

    private static function renderCancellationBody(Absence $absence, User $surgeon): string
    {
        return sprintf(
            "Bonjour,\n\nJe vous informe que mon congé prévu du %s au %s inclus est annulé.\n\nMerci d'en prendre note.\n\nBien à vous,\n%s",
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
            self::drName($surgeon),
        );
    }

    private static function drName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return 'Dr ' . ($name !== '' ? $name : (string) $user->getEmail());
    }

    private static function isSurgeon(User $user): bool
    {
        return in_array('ROLE_SURGEON', $user->getRoles(), true);
    }
}
