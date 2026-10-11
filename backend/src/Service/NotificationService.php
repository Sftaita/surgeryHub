<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Enum\CatalogueRequestKind;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

class NotificationService
{
    private const INSTRUMENTIST_INVITATION_PATH = '/complete-account';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(string:FRONTEND_URL)%')]
        private readonly string $frontendUrl,
        #[Autowire('%env(string:MAILER_FROM_ADDRESS)%')]
        private readonly string $fromAddress,
        #[Autowire('%env(string:MAILER_FROM_NAME)%')]
        private readonly string $fromName,
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Déclaration -> managers/admins globaux. H5b (D-132) — through NotificationDispatcher
     * (in-app per preferences, traced); call AFTER the mission is flushed (it needs its id).
     */
    public function missionDeclaredNotifyManagersAdmins(Mission $mission): void
    {
        foreach ($this->userRepository->findManagersAndAdmins(true) as $user) {
            $this->declarationNotice($user, $mission, NotificationType::MISSION_DECLARED);
        }
    }

    /** Approbation -> instrumentiste (H5b: dispatcher). */
    public function missionDeclaredApprovedNotifyInstrumentist(Mission $mission): void
    {
        if ($mission->getInstrumentist() instanceof User) {
            $this->declarationNotice($mission->getInstrumentist(), $mission, NotificationType::MISSION_DECLARED_APPROVED);
        }
    }

    /** Rejet -> instrumentiste (H5b: dispatcher). */
    public function missionDeclaredRejectedNotifyInstrumentist(Mission $mission): void
    {
        if ($mission->getInstrumentist() instanceof User) {
            $this->declarationNotice($mission->getInstrumentist(), $mission, NotificationType::MISSION_DECLARED_REJECTED);
        }
    }

    private function declarationNotice(User $user, Mission $mission, NotificationType $type): void
    {
        $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $user,
            type: $type,
            inAppPayload: ['missionId' => $mission->getId(), 'siteId' => $mission->getSite()?->getId(), 'status' => $mission->getStatus()->value],
            mission: $mission,
            episodeRef: sprintf('declaration:%s:%d', $type->value, (int) $mission->getId()),
        ));
    }

    /**
     * H5a (category C) — account invitation, whatever the role, through NotificationDispatcher
     * with a MANDATORY email (never subject to preferences), no in-app, no push. Traced like
     * every other email (NotificationDispatch, OutboundNotification QUEUED → SENT / FAILED,
     * attempts, activity) — but CONFIDENTIAL: the link carries the invitation token, so the
     * rendered body is never copied into outbound_notification and the token never appears
     * in any payload (the episode only carries a fragment of its hash).
     *
     * invitationLastSentAt is set only when the email was really queued (QUEUED / SENT) —
     * it timestamps the hand-off to the queue, not a delivery. Throws otherwise, so the
     * callers' existing "email not sent" warnings still fire.
     */
    public function sendUserInvitation(User $user): void
    {
        $token = $user->getInvitationToken();
        if ($token === null || $token === '') {
            throw new \LogicException('Invitation token is missing.');
        }

        $roleLabel = self::invitationRoleLabel($user);
        $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $user,
            type: NotificationType::ACCOUNT_INVITATION,
            inAppPayload: [],
            email: new EmailContent(
                subject: sprintf('SurgicalHub — Activez votre compte %s', $roleLabel),
                htmlTemplate: 'emails/instrumentist_invitation.html.twig',
                context: [
                    'displayName'   => $this->resolveDisplayName($user),
                    'roleLabel'     => $roleLabel,
                    'invitationUrl' => $this->buildFrontendUrl(self::INSTRUMENTIST_INVITATION_PATH, ['token' => $token]),
                    'expiresAt'     => $user->getInvitationExpiresAt(),
                ],
                textTemplate: 'emails/instrumentist_invitation.txt.twig',
            ),
            episodeRef: 'invitation:' . substr(hash('sha256', $token), 0, 16),
            subjectType: 'User',
            subjectId: $user->getId(),
            emailPolicy: NotificationEmailPolicy::MANDATORY,
            inApp: false,
            confidential: true,
        ));

        if (!self::emailQueued($dispatch)) {
            throw new \RuntimeException('Invitation email could not be queued.');
        }

        $user->setInvitationLastSentAt(new \DateTimeImmutable());
    }

    public function sendInstrumentistInvitation(User $user): void
    {
        $this->sendUserInvitation($user);
        $this->em->flush();
    }

    public function sendSurgeonInvitation(User $user): void
    {
        $this->sendUserInvitation($user);
        $this->em->flush();
    }

    /**
     * H5a (category C) — an email address change: the PREVIOUS address is told (security
     * signal), the new one is confirmed. MANDATORY email, explicit address recorded on the
     * delivery, one episode per change and side.
     *
     * @return bool whether the email was really queued
     */
    public function sendEmailChangeNotice(User $user, string $address, string $side, string $changeRef, string $oldEmail, string $newEmail): bool
    {
        $old = $side === 'old';
        $dispatch = $this->dispatcher->dispatch(new NotificationIntent(
            recipient: $user,
            type: NotificationType::ACCOUNT_EMAIL_CHANGED,
            inAppPayload: [],
            email: new EmailContent(
                subject: $old ? 'SurgicalHub — Votre adresse email a été modifiée' : 'SurgicalHub — Votre adresse email est confirmée',
                htmlTemplate: $old ? 'emails/user_email_changed_old_address.html.twig' : 'emails/user_email_changed_new_address.html.twig',
                context: $old
                    ? ['displayName' => $this->resolveDisplayName($user), 'oldEmail' => $oldEmail, 'newEmail' => $newEmail]
                    : ['displayName' => $this->resolveDisplayName($user), 'newEmail' => $newEmail],
                textTemplate: $old ? 'emails/user_email_changed_old_address.txt.twig' : 'emails/user_email_changed_new_address.txt.twig',
            ),
            episodeRef: sprintf('email_change:%s:%s', $changeRef, $side),
            subjectType: 'User',
            subjectId: $user->getId(),
            emailPolicy: NotificationEmailPolicy::MANDATORY,
            inApp: false,
            emailAddress: $address,
        ));

        return self::emailQueued($dispatch);
    }

    // ── H5a — email CONTENT of the business notifications ────────────────────
    //
    // These methods only build what the email says (subject, template, context): the
    // decision to send it, the channel policy, the delivery record and its honest status
    // belong to NotificationDispatcher, which the handlers call with these contents.
    // Formerly each one queued its own email (D-083/D-084 OutboundNotification only, no
    // NotificationDispatch, no channel decision recorded).

    /** D-083 / D-120 — encoding reminder (automatic D+1 or manual relaunch). */
    public function missionEncodingReminderEmail(Mission $mission, User $instrumentist): EmailContent
    {
        return new EmailContent('SurgicalHub — Encodage à finaliser', 'emails/mission_encoding_reminder.html.twig', [
            'firstname'  => $instrumentist->getFirstname(),
            'missionUrl' => sprintf('%s/app/i/missions/%d', $this->frontendUrl, $mission->getId()),
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($instrumentist),
        ]);
    }

    /** Point 8 (audit UX) — OPEN mission published for this surgeon. No patient data. */
    public function missionOpenSurgeonEmail(Mission $mission, User $surgeon): EmailContent
    {
        return new EmailContent('SurgicalHub — Nouvelle mission ouverte', 'emails/mission_open_notify_surgeon.html.twig', [
            'firstname'   => $surgeon->getFirstname(),
            'dateLabel'   => $mission->getStartAt()?->format('d/m/Y'),
            'timeLabel'   => self::timeLabel($mission),
            'siteName'    => $mission->getSite()?->getName(),
            'typeLabel'   => self::typeLabel($mission),
            'missionUrl'  => sprintf('%s/app/s', $this->frontendUrl),
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($surgeon),
        ]);
    }

    /** D-125 — nominative request: she must accept or decline. */
    public function missionOfferedEmail(Mission $mission, User $instrumentist): EmailContent
    {
        return $this->missionDispatchEmail($mission, $instrumentist, 'SurgicalHub — Une mission vous est proposée',
            'emails/mission_offered.html.twig', sprintf('%s/app/i/offers', $this->frontendUrl), []);
    }

    /** D-125 — the nominative request was declined: the mission is still to cover. */
    public function missionOfferDeclinedEmail(Mission $mission, User $manager, string $instrumentistName, ?string $reason): EmailContent
    {
        return $this->missionDispatchEmail($mission, $manager, 'SurgicalHub — Demande de mission refusée',
            'emails/mission_offer_declined.html.twig', sprintf('%s/app/m/missions/%d', $this->frontendUrl, $mission->getId()),
            ['instrumentistName' => $instrumentistName, 'reason' => $reason]);
    }

    /** @param array<string,mixed> $extraContext */
    private function missionDispatchEmail(Mission $mission, User $recipient, string $subject, string $template, string $url, array $extraContext): EmailContent
    {
        $surgeon = $mission->getSurgeon();

        return new EmailContent($subject, $template, [
            'greeting'    => $this->greetingFor($recipient),
            'dateLabel'   => $mission->getStartAt()?->format('d/m/Y'),
            'timeLabel'   => self::timeLabel($mission),
            'siteName'    => $mission->getSite()?->getName(),
            'typeLabel'   => self::typeLabel($mission),
            'surgeonName' => $surgeon !== null ? trim(($surgeon->getFirstname() ?? '') . ' ' . ($surgeon->getLastname() ?? '')) : null,
            'missionUrl'  => $url,
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($recipient),
        ] + $extraContext);
    }

    /** D-093 — the instrumentist's catalogue proposal was accepted. */
    public function catalogueRequestResolvedEmail(Mission $mission, User $instrumentist, string $label, string $kindLabel): EmailContent
    {
        return new EmailContent('SurgicalHub — Votre proposition a été acceptée', 'emails/catalogue_request_resolved.html.twig', [
            'firstname'  => $instrumentist->getFirstname(),
            'label'      => $label,
            'kindLabel'  => $kindLabel,
            'missionUrl' => sprintf('%s/app/i/missions/%d', $this->frontendUrl, $mission->getId()),
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($instrumentist),
        ]);
    }

    /** D-093 — the instrumentist's catalogue proposal was not retained (never deleted — IGNORED). */
    public function catalogueRequestIgnoredEmail(
        Mission $mission,
        User $instrumentist,
        string $label,
        string $kindLabel,
        ?CatalogueRequestIgnoreReason $ignoreReason = null,
        ?string $explanation = null,
    ): EmailContent {
        // French wording produced here only — the message carries the stable business code.
        return new EmailContent('SurgicalHub — Votre proposition n\'a pas été retenue', 'emails/catalogue_request_ignored.html.twig', [
            'firstname'   => $instrumentist->getFirstname(),
            'label'       => $label,
            'kindLabel'   => $kindLabel,
            'reasonLabel' => $ignoreReason?->label(),
            'explanation' => $explanation,
            'missionUrl'  => sprintf('%s/app/i/missions/%d', $this->frontendUrl, $mission->getId()),
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($instrumentist),
        ]);
    }

    /** D-113 (amends D-094) — a new catalogue proposal awaits a manager. */
    public function catalogueRequestCreatedEmail(Mission $mission, User $manager, int $requestId, string $label, string $kindLabel, CatalogueRequestKind $kind): EmailContent
    {
        $requestsUrl = sprintf('%s/app/m/catalogue/requests?kind=%s&requestId=%d', $this->frontendUrl, $kind->value, $requestId);

        return new EmailContent('SurgicalHub — Nouvelle demande Catalogue à valider', 'emails/catalogue_request_created.html.twig', [
            'firstname'     => $manager->getFirstname(),
            'requesterName' => trim(($mission->getInstrumentist()?->getFirstname() ?? '') . ' ' . ($mission->getInstrumentist()?->getLastname() ?? '')),
            'label'         => $label,
            'kindLabel'     => $kindLabel,
            'siteName'      => $mission->getSite()?->getName(),
            'requestsUrl'   => $requestsUrl,
            'missionUrl'    => $requestsUrl,
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($manager),
        ]);
    }

    /**
     * Lot 5 (D-099) — a surgeon requested a mission (manager side). Opt-in: email=false by
     * default, sent only to a manager who ticked "E-mail" for this type in their preferences.
     * Dates are formatted from the message's own offset (same as the push), never re-zoned.
     */
    public function surgeonMissionRequestCreatedEmail(User $manager, string $surgeonName, string $siteName, string $startAt, string $endAt): EmailContent
    {
        $requestsUrl = sprintf('%s/app/m/missions/requests', $this->frontendUrl);
        $start = new \DateTimeImmutable($startAt);
        $end = new \DateTimeImmutable($endAt);

        return new EmailContent('SurgicalHub — Nouvelle demande de mission', 'emails/surgeon_mission_request_created.html.twig', [
            'firstname'   => $manager->getFirstname(),
            'surgeonName' => $surgeonName,
            'siteName'    => $siteName,
            'date'        => $start->format('d/m/Y'),
            'startTime'   => $start->format('H:i'),
            'endTime'     => $end->format('H:i'),
            'requestsUrl' => $requestsUrl,
            'missionUrl'  => $requestsUrl,
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($manager),
        ]);
    }

    /** Lot 5 (D-099) — the surgeon's mission request was accepted (a real Mission exists). */
    public function surgeonMissionRequestAcceptedEmail(Mission $mission, User $surgeon): EmailContent
    {
        return new EmailContent('SurgicalHub — Votre demande de mission a été acceptée', 'emails/surgeon_mission_request_accepted.html.twig', [
            'firstname'  => $surgeon->getFirstname(),
            'siteName'   => $mission->getSite()?->getName(),
            'startAt'    => $mission->getStartAt(),
            'missionUrl' => sprintf('%s/app/s/missions/%d', $this->frontendUrl, $mission->getId()),
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($surgeon),
        ]);
    }

    /** Lot 5 (D-099) — the surgeon's mission request was declined (no Mission ever existed). */
    public function surgeonMissionRequestRejectedEmail(User $surgeon, string $siteName, \DateTimeImmutable $startAt, string $reviewComment): EmailContent
    {
        $requestsUrl = sprintf('%s/app/s/requests', $this->frontendUrl);

        return new EmailContent('SurgicalHub — Votre demande de mission a été refusée', 'emails/surgeon_mission_request_rejected.html.twig', [
            'firstname'     => $surgeon->getFirstname(),
            'siteName'      => $siteName,
            'startAt'       => $startAt,
            'reviewComment' => $reviewComment,
            'requestsUrl'   => $requestsUrl,
            'missionUrl'    => $requestsUrl,
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($surgeon),
        ]);
    }

    /** Lot 6 (D-100) — the surgeon's encoding anomaly report was resolved. */
    public function encodingAnomalyResolvedEmail(Mission $mission, User $surgeon, string $resolutionComment): EmailContent
    {
        return new EmailContent('SurgicalHub — Votre signalement a été traité', 'emails/encoding_anomaly_resolved.html.twig', [
            'firstname'         => $surgeon->getFirstname(),
            'resolutionComment' => $resolutionComment,
            'missionUrl'        => sprintf('%s/app/s/missions/%d', $this->frontendUrl, $mission->getId()),
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($surgeon),
        ]);
    }

    // ── Absences — manager reminder emails (D-051, H5a) ──────────────────────
    //
    // ONE INDIVIDUAL email PER selected person, to their OWN address — never to a fixed
    // mailbox. `boost.conge@gmail.com` only ever appears as plain text inside the
    // request-missing message (asking the recipient to reply there), never as a recipient
    // (D-051 second amendment). H5a: sent by AbsenceReminderController through the
    // dispatcher (MANDATORY email, traced, idempotent per reminder batch).

    public function absenceRequestMissingEmail(User $user, string $message): EmailContent
    {
        return new EmailContent('SurgicalHub — Vos congés à venir', 'emails/absences_request_missing.html.twig', [
            'greeting' => $this->greetingFor($user),
            'message'  => $message,
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($user),
        ]);
    }

    /**
     * ALL of the person's future absences (dateEnd >= today, no 3-month cap). Dates only:
     * the free-text reason of an absence never leaves the Absence (H4 decision A.5), and no
     * entity travels in the queued message.
     *
     * @param \App\Entity\Absence[] $absences
     */
    public function absenceEncodedRecapEmail(User $user, array $absences, string $message): EmailContent
    {
        return new EmailContent('SurgicalHub — Récapitulatif de vos congés encodés', 'emails/absences_confirm_encoded.html.twig', [
            'greeting' => $this->greetingFor($user),
            'absences' => array_map(static fn (\App\Entity\Absence $a) => [
                'dateStart' => $a->getDateStart()->format('Y-m-d'),
                'dateEnd'   => $a->getDateEnd()->format('Y-m-d'),
            ], array_values($absences)),
            'message'  => $message,
            'notificationPreferencesUrl' => $this->notificationPreferencesUrl($user),
        ]);
    }

    private static function timeLabel(Mission $mission): ?string
    {
        return $mission->getStartAt() !== null && $mission->getEndAt() !== null
            ? $mission->getStartAt()->format('H:i') . ' – ' . $mission->getEndAt()->format('H:i')
            : null;
    }

    private static function typeLabel(Mission $mission): string
    {
        return $mission->getType() === \App\Enum\MissionType::CONSULTATION ? 'Consultation' : 'Bloc opératoire';
    }

    private static function invitationRoleLabel(User $user): string
    {
        $roles = $user->getRoles();

        return match (true) {
            in_array('ROLE_ADMIN', $roles, true)        => 'administrateur',
            in_array('ROLE_MANAGER', $roles, true)      => 'manager',
            in_array('ROLE_SURGEON', $roles, true)      => 'chirurgien',
            default                                     => 'instrumentiste',
        };
    }

    /** The email delivery of this dispatch was really handed to the queue (or already sent). */
    private static function emailQueued(NotificationDispatch $dispatch): bool
    {
        foreach ($dispatch->getDeliveries() as $delivery) {
            if ($delivery->getChannel() === OutboundNotificationChannel::EMAIL) {
                return in_array($delivery->getStatus(), [OutboundNotificationStatus::QUEUED, OutboundNotificationStatus::SENT], true);
            }
        }

        return false;
    }

    /**
     * "Bonjour Dr {lastname}" for surgeons, "Bonjour {firstname}" for everyone else
     * (instrumentists) — falls back to "Bonjour" alone if the relevant name part is missing,
     * never an empty/broken greeting.
     */
    public function greetingFor(User $user): string
    {
        if (in_array('ROLE_SURGEON', $user->getRoles(), true)) {
            $lastname = $user->getLastname();
            return $lastname ? "Bonjour Dr {$lastname}" : 'Bonjour';
        }

        $firstname = $user->getFirstname();
        return $firstname ? "Bonjour {$firstname}" : 'Bonjour';
    }

    /**
     * URL de l'écran de préférences de notification du destinataire, pour le CTA
     * discret ajouté en bas des emails applicatifs (lot notifications catalogue,
     * follow-up D-093) — jamais une URL inventée : uniquement les routes React
     * réelles qui exposent NotificationPreferencesSection aujourd'hui
     * (ProfilePage instrumentiste/manager). `null` pour tout rôle sans écran dédié
     * (chirurgien — même limite déjà documentée dans NotificationTargetResolver pour
     * la route unique `/app/s`) : le partial _notification_preferences_cta.html.twig
     * n'affiche alors rien plutôt que de pointer vers une route qui n'existe pas.
     */
    public function notificationPreferencesUrl(User $user): ?string
    {
        $roles = $user->getRoles();

        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return $this->buildFrontendUrl('/app/i/profile');
        }

        // Socle mobile partagé chirurgien (D-095) — `/app/s/profile` existe réellement
        // depuis ce lot (ProfilePage commune) ; auparavant ce cas retournait null
        // (aucun écran de préférences côté chirurgien), volontairement laissé tel quel
        // par D-094 en attendant que la route existe.
        if (in_array('ROLE_SURGEON', $roles, true)) {
            return $this->buildFrontendUrl('/app/s/profile');
        }

        if (in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true)) {
            return $this->buildFrontendUrl('/app/m/profile');
        }

        return null;
    }

    private function buildFrontendUrl(string $path, array $query = []): string
    {
        $base = rtrim($this->frontendUrl, '/');
        $normalizedPath = '/' . ltrim($path, '/');
        $queryString = http_build_query($query);

        if ($queryString === '') {
            return $base . $normalizedPath;
        }

        return $base . $normalizedPath . '?' . $queryString;
    }

    private function resolveDisplayName(User $user): string
    {
        $firstname = $user->getFirstname();
        $lastname = $user->getLastname();

        $name = trim((string) ($firstname ?? '') . ' ' . (string) ($lastname ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}