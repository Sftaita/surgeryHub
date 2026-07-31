<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Enum\PlanningVersionStatus;
use App\Enum\PublicationChannel;
use App\Message\SendBillingEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Manual "Renvoyer le planning par e-mail" action (D-090, anomalie fonctionnelle 1) —
 * a manager re-sending one person's CURRENTLY PUBLISHED plan on demand, independent of
 * any deploy/redeploy and any diff. Never fires for anyone else, never depends on
 * whether anything changed recently.
 *
 * Deliberately reuses the exact same PDF templates (`pdf/planning_instrumentist.html.twig`
 * / `pdf/planning_surgeon.html.twig`), email templates (`emails/planning_instrumentist.html.twig`
 * / `emails/planning_surgeon.html.twig`) and subject format
 * (`Planning du {from} au {to}` — D-058) as the initial-deploy email
 * (PlanningDeployPdfsMessageHandler) — never a second, divergent format for "the same
 * information, resent". The one deliberate content difference: no "missions disponibles"
 * section (that's specific to the deploy-time claim-pool nudge, not a plain resend) and no
 * global manager PDF attachment for surgeons (manager-only content, irrelevant to a resend).
 */
class PlanningResendService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfService $pdfService,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {}

    /**
     * @return array{missionCount: int, email: string}
     * @throws NotFoundHttpException   version or user not found, or user has no role-appropriate missions in it
     * @throws ConflictHttpException   the version is not the currently published (ACTIVE) one
     */
    public function resend(PlanningVersion $version, User $target, User $actor): array
    {
        // "Exclusivement le planning publié, jamais le brouillon" — enforced structurally,
        // not just by convention: a DRAFT/ARCHIVED version simply cannot be resent.
        if ($version->getStatus() !== PlanningVersionStatus::ACTIVE) {
            throw new ConflictHttpException('Seul le planning actuellement publié (version ACTIVE) peut être renvoyé.');
        }

        $roles = $target->getRoles();
        $isInstrumentist = in_array('ROLE_INSTRUMENTIST', $roles, true);
        $isSurgeon       = in_array('ROLE_SURGEON', $roles, true);

        if (!$isInstrumentist && !$isSurgeon) {
            throw new NotFoundHttpException('Cet utilisateur n\'a pas de planning instrumentiste ou chirurgien à renvoyer.');
        }

        if ($target->getEmail() === null || $target->getEmail() === '') {
            throw new NotFoundHttpException('Cet utilisateur n\'a pas d\'adresse email.');
        }

        $missions = $isInstrumentist
            ? $this->loadMissions($version, 'm.instrumentist', $target, [MissionStatus::ASSIGNED])
            : $this->loadMissions($version, 'm.surgeon', $target, [MissionStatus::OPEN, MissionStatus::ASSIGNED]);

        if (empty($missions)) {
            throw new NotFoundHttpException('Aucune mission publiée pour cet utilisateur sur cette version.');
        }

        $fromDate = $version->getPeriodStart();
        $toDate   = $version->getPeriodEnd();
        $subject  = sprintf('Planning du %s au %s', $fromDate->format('d/m/Y'), $toDate->format('d/m/Y'));
        $name     = self::displayName($target);

        if ($isInstrumentist) {
            $pdf = $this->pdfService->generateFromTemplate('pdf/planning_instrumentist.html.twig', [
                'instrumentist' => $target,
                'missions'      => $missions,
                'periodFrom'    => $fromDate,
                'periodTo'      => $toDate,
            ]);
            $htmlTemplate = 'emails/planning_instrumentist.html.twig';
            $context = [
                'instrumentist'        => $target,
                'periodFrom'           => $fromDate,
                'periodTo'             => $toDate,
                'missionCount'         => count($missions),
                'availableMissions'    => [],
                'availableMissionsUrl' => null,
            ];
        } else {
            $pdf = $this->pdfService->generateFromTemplate('pdf/planning_surgeon.html.twig', [
                'surgeon'    => $target,
                'missions'   => $missions,
                'periodFrom' => $fromDate,
                'periodTo'   => $toDate,
            ]);
            $covered = count(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::ASSIGNED));
            $htmlTemplate = 'emails/planning_surgeon.html.twig';
            $context = [
                'surgeon'        => $target,
                'periodFrom'     => $fromDate,
                'periodTo'       => $toDate,
                'totalCount'     => count($missions),
                'coveredCount'   => $covered,
                'uncoveredCount' => count($missions) - $covered,
            ];
        }

        $filename = sprintf(
            'planning-%s%s-%s-%s.pdf',
            $isSurgeon ? 'chirurgien-' : '',
            strtolower(str_replace(' ', '-', $name)),
            $fromDate->format('Y-m-d'),
            $toDate->format('Y-m-d'),
        );

        $emailStatus = 'SENT';
        $emailError  = null;
        try {
            $this->bus->dispatch(new SendBillingEmailMessage(
                to: $target->getEmail(),
                cc: [],
                subject: $subject,
                fromAddress: $this->fromAddress,
                fromName: $this->fromName,
                htmlTemplate: $htmlTemplate,
                context: $context,
                attachmentBase64: base64_encode($pdf),
                attachmentFilename: $filename,
            ));
        } catch (\Throwable $e) {
            $emailStatus = 'FAILED';
            $emailError  = $e->getMessage();
        }

        // Historique des notifications (D-090 exigence explicite) — un seul NotificationEvent,
        // pour ce destinataire uniquement, jamais pour personne d'autre. Statut et éventuelle
        // erreur d'envoi enregistrés, cohérent avec le reste du modèle NotificationEvent.
        $event = (new NotificationEvent())
            ->setUser($target)
            ->setEventType(NotificationType::PLANNING_RESENT_MANUAL->value)
            ->setChannel(PublicationChannel::EMAIL)
            ->setSentAt(new \DateTimeImmutable())
            ->setPayload([
                'planningVersionId' => $version->getId(),
                'periodFrom'        => $fromDate->format('Y-m-d'),
                'periodTo'          => $toDate->format('Y-m-d'),
                'missionCount'      => count($missions),
                'actorId'           => $actor->getId(),
                'actorName'         => self::displayName($actor),
                'status'            => $emailStatus,
                'error'             => $emailError,
            ]);
        $this->em->persist($event);
        $this->em->flush();

        if ($emailStatus === 'FAILED') {
            throw new \RuntimeException('L\'envoi de l\'email a échoué : ' . $emailError);
        }

        return ['missionCount' => count($missions), 'email' => $target->getEmail()];
    }

    /** @param MissionStatus[] $statuses @return Mission[] */
    private function loadMissions(PlanningVersion $version, string $field, User $user, array $statuses): array
    {
        return $this->em->createQuery(
            "SELECT m FROM App\Entity\Mission m
             WHERE m.planningVersion = :version AND {$field} = :user AND m.status IN (:statuses)
             ORDER BY m.startAt ASC"
        )
            ->setParameter('version', $version)
            ->setParameter('user', $user)
            ->setParameter('statuses', $statuses)
            ->getResult();
    }

    private static function displayName(User $u): string
    {
        $name = trim(($u->getFirstname() ?? '') . ' ' . ($u->getLastname() ?? ''));
        return $name !== '' ? $name : ($u->getEmail() ?? '');
    }
}
