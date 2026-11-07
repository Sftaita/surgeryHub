<?php

namespace App\Absence;

use App\Entity\User;
use App\Enum\AbsenceImpactLevel;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Enum\NotificationType;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * H4 (D-130) — informs the people an absence impacts at levels N1 (usual schedule) and N2
 * (DRAFT planning), through NotificationDispatcher only:
 *
 *   surgeon absent      → the planned instrumentist   ABSENCE_OCCURRENCE_CANCELLED (level in payload)
 *   instrumentist absent → the surgeon                ABSENCE_OCCURRENCE_UNCOVERED (level in payload)
 *
 * The vocabulary never says "mission annulée" at these levels (D-127). N3 (published
 * missions) is AbsenceMissionsReactedMessageHandler's job — its notification types are
 * distinct, so one occurrence is only ever qualified once.
 *
 * Grouping: one dispatch (and one in-app item) per occurrence — precise history — but ONE
 * email and ONE push per recipient for the whole absence-processing run (dispatcher BATCH).
 * Idempotency: the episode is the occurrence's identity for this absence and level, so a
 * Messenger retry, or an update of the absence that keeps an occurrence, sends nothing again.
 *
 * N2 notifications are deliberately NOT attached to the DRAFT Mission (missionId stays in
 * the payload): deleting a DRAFT deletes its feed items (PlanningDraftService), and this
 * information must survive it.
 */
class AbsenceImpactNotifier
{
    private const WEEKDAYS = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<AbsenceImpact> $impacts   N1 / N2 impacts to inform (other levels are ignored)
     * @param list<AbsenceImpact> $recovered N1 occurrences an update no longer impacts (instrumentist absence only)
     */
    public function notify(string $absentName, string $dateStart, string $dateEnd, array $impacts, array $recovered = []): void
    {
        $byType = [];
        foreach ([['IMPACTED', $impacts], ['RESTORED', $recovered]] as [$status, $list]) {
            foreach ($list as $impact) {
                if ($impact->level === AbsenceImpactLevel::PUBLISHED || $impact->recipientId() === null) {
                    continue;
                }
                $recipient = $this->em->find(User::class, $impact->recipientId());
                if ($recipient === null) {
                    continue;
                }
                $type = self::typeFor($impact, $status);
                if ($type === null) {
                    continue; // a surgeon-side habitual occurrence is restored by D-104's own notice
                }
                if ($status === 'RESTORED' && !$this->wasCommunicated($impact, (int) $recipient->getId())) {
                    continue; // nothing had reached her about this occurrence: nothing to correct
                }
                $byType[$type->value][] = $this->intent($recipient, $type, $impact, $status, $absentName, $dateStart, $dateEnd);
            }
        }

        foreach ($byType as $typeValue => $intents) {
            $type = NotificationType::from($typeValue);
            try {
                $this->dispatcher->dispatchBatch(
                    $intents,
                    fn (User $u, array $its) => $this->email($u, $type, $its),
                    fn (User $u, array $its) => $this->push($type, $its),
                );
            } catch (\Throwable $e) {
                $this->logger->error('AbsenceImpactNotifier: dispatch failed', ['type' => $typeValue, 'error' => $e->getMessage()]);
            }
        }
    }

    private function intent(User $recipient, NotificationType $type, AbsenceImpact $impact, string $status, string $absentName, string $dateStart, string $dateEnd): NotificationIntent
    {
        $date = new \DateTimeImmutable($impact->date);

        return new NotificationIntent(
            recipient: $recipient,
            type: $type,
            inAppPayload: [
                'level'             => $impact->level->value,
                'status'            => $status,
                'absenceId'         => $impact->absenceId,
                'absentName'        => $absentName,
                'absenceStart'      => $dateStart,
                'absenceEnd'        => $dateEnd,
                'date'              => $impact->date,
                'occurrenceDate'    => $date->format('d/m/Y'),
                'missionDate'       => $date->format('d/m/Y'),
                'weekday'           => self::WEEKDAYS[(int) $date->format('N')],
                'startTime'         => $impact->startTime,
                'endTime'           => $impact->endTime,
                'periodLabel'       => $impact->periodLabel(),
                'siteName'          => $impact->siteName,
                'surgeonName'       => $impact->surgeonName,
                'instrumentistName' => $impact->instrumentistName,
                'missionId'         => $impact->missionId,
                'postId'            => $impact->postId,
                'sentence'          => self::sentence($impact, $status),
                'badge'             => self::badge($impact, $status),
            ],
            // Never the DRAFT Mission itself — see class docblock.
            mission: null,
            episodeRef: $impact->key() . ':' . $status,
            subjectType: 'AbsenceOccurrence',
            subjectId: $impact->absenceId,
        );
    }

    /**
     * IMPACTED: the D-127 cases. RESTORED (the absence no longer covers the occurrence): a
     * correction, reusing the existing "occurrence restored" type on the surgeon-absence side
     * (DRAFT only — a habitual occurrence is D-104's), and the "uncovered" type with
     * status RESTORED on the instrumentist-absence side (D-107's "de nouveau disponible").
     */
    private static function typeFor(AbsenceImpact $impact, string $status): ?NotificationType
    {
        if ($impact->absentRole === AbsenceImpact::ROLE_INSTRUMENTIST) {
            return NotificationType::ABSENCE_OCCURRENCE_UNCOVERED;
        }
        if ($status === 'IMPACTED') {
            return NotificationType::ABSENCE_OCCURRENCE_CANCELLED;
        }

        return $impact->level === AbsenceImpactLevel::DRAFT ? NotificationType::ABSENCE_OCCURRENCE_RESTORED : null;
    }

    /**
     * A correction is only owed if the initial information really reached the recipient:
     * at least one channel of the IMPACTED dispatch of this occurrence was accepted (in-app
     * created, push / email accepted by the provider).
     */
    private function wasCommunicated(AbsenceImpact $impact, int $recipientId): bool
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM notification_dispatch nd
             JOIN notification_dispatch_delivery d ON d.dispatch_id = nd.id
             JOIN outbound_notification o ON o.id = d.outbound_notification_id
             WHERE nd.recipient_user_id = ? AND nd.episode_ref = ? AND o.status = 'SENT'",
            [$recipientId, mb_substr($impact->key() . ':IMPACTED', 0, 150)],
        ) > 0;
    }

    /**
     * The D-127 information, worded without needless gender agreement (final H4 decision):
     * "indisponible", "le créneau prévu avec vous", never "absent(e)" / "prévu(e)".
     */
    public static function sentence(AbsenceImpact $impact, string $status = 'IMPACTED'): string
    {
        $date = (new \DateTimeImmutable($impact->date))->format('d/m/Y');
        $isDraft = $impact->level === AbsenceImpactLevel::DRAFT;

        if ($impact->absentRole === AbsenceImpact::ROLE_SURGEON) {
            if ($status === 'RESTORED') {
                return sprintf('Le créneau prévu avec le Dr %s le %s n\'est plus concerné par cette absence.', $impact->surgeonName, $date);
            }

            return $isDraft
                ? sprintf('Le Dr %s sera indisponible le %s : le créneau qui vous était attribué dans le planning en préparation est impacté.', $impact->surgeonName, $date)
                : sprintf('Le Dr %s sera indisponible le %s : votre créneau habituel avec le Dr %s est impacté.', $impact->surgeonName, $date, $impact->surgeonName);
        }

        if ($status === 'RESTORED') {
            return $isDraft
                ? sprintf('Le créneau prévu avec %s le %s n\'est plus concerné par cette absence.', $impact->instrumentistName, $date)
                : sprintf('%s est de nouveau disponible le %s : votre créneau habituel n\'est plus concerné.', $impact->instrumentistName, $date);
        }

        return $isDraft
            ? sprintf('%s sera indisponible le %s : le créneau prévu avec vous dans le planning en préparation doit être recouvert.', $impact->instrumentistName, $date)
            : sprintf('%s sera indisponible le %s sur votre créneau habituel.', $impact->instrumentistName, $date);
    }

    private static function badge(AbsenceImpact $impact, string $status): string
    {
        if ($status === 'RESTORED') {
            return 'N\'est plus concerné';
        }

        return $impact->level === AbsenceImpactLevel::DRAFT ? 'Planning en préparation' : 'Créneau habituel';
    }

    /** @param list<NotificationIntent> $intents */
    private function email(User $recipient, NotificationType $type, array $intents): EmailContent
    {
        $items = array_map(static fn (NotificationIntent $i) => $i->inAppPayload, $intents);
        usort($items, static fn (array $a, array $b) => [$a['date'], $a['startTime']] <=> [$b['date'], $b['startTime']]);
        $first = $items[0];
        $count = count($items);
        $common = [
            'recipientName' => self::name($recipient),
            'dateStart'     => (new \DateTimeImmutable($first['absenceStart']))->format('d/m/Y'),
            'dateEnd'       => (new \DateTimeImmutable($first['absenceEnd']))->format('d/m/Y'),
            'notificationPreferencesUrl' => $this->notificationService->notificationPreferencesUrl($recipient),
        ];

        if ($type === NotificationType::ABSENCE_OCCURRENCE_RESTORED) {
            return new EmailContent(
                subject: $count > 1
                    ? sprintf('Absence du Dr %s modifiée — %d créneaux ne sont plus concernés', $first['surgeonName'], $count)
                    : sprintf('Absence du Dr %s modifiée — le créneau du %s n\'est plus concerné', $first['surgeonName'], $first['occurrenceDate']),
                htmlTemplate: 'emails/absence_surgeon_occurrence_neutralized.html.twig',
                context: $common + ['surgeonName' => $first['surgeonName'], 'occurrences' => $items, 'allRestored' => true],
            );
        }

        if ($type === NotificationType::ABSENCE_OCCURRENCE_CANCELLED) {
            return new EmailContent(
                subject: $count > 1
                    ? sprintf('Absence du Dr %s — %d créneaux vous concernant', $first['surgeonName'], $count)
                    : sprintf('Absence du Dr %s le %s — un créneau vous concernant est impacté', $first['surgeonName'], $first['occurrenceDate']),
                htmlTemplate: 'emails/absence_surgeon_occurrence_neutralized.html.twig',
                context: $common + ['surgeonName' => $first['surgeonName'], 'occurrences' => $items],
            );
        }

        $impacted  = array_values(array_filter($items, static fn (array $i) => $i['status'] === 'IMPACTED'));
        $recovered = array_values(array_filter($items, static fn (array $i) => $i['status'] === 'RESTORED'));

        return new EmailContent(
            subject: match (true) {
                $impacted === [] => sprintf('Absence de %s modifiée — créneaux de nouveau couverts', $first['absentName']),
                count($impacted) > 1 => sprintf('Absence de %s — %d créneaux à recouvrir', $first['absentName'], count($impacted)),
                default => sprintf('Absence de %s le %s', $first['absentName'], $impacted[0]['occurrenceDate']),
            },
            htmlTemplate: 'emails/absence_instrumentist_occurrence_impact.html.twig',
            context: $common + ['instrumentistName' => $first['absentName'], 'impacted' => $impacted, 'recovered' => $recovered],
        );
    }

    /** @param list<NotificationIntent> $intents */
    private function push(NotificationType $type, array $intents): PushContent
    {
        $first = $intents[0]->inAppPayload;
        $count = count($intents);

        if ($type === NotificationType::ABSENCE_OCCURRENCE_RESTORED) {
            return new PushContent(
                sprintf('Absence du Dr %s modifiée', $first['surgeonName']),
                $count > 1 ? sprintf('%d créneaux ne sont plus concernés.', $count) : $first['sentence'],
                ['type' => $type->value, 'url' => '/app/i/planning'],
            );
        }

        return $type === NotificationType::ABSENCE_OCCURRENCE_CANCELLED
            ? new PushContent(
                sprintf('Absence du Dr %s', $first['surgeonName']),
                $count > 1 ? sprintf('%d créneaux vous concernant sont impactés.', $count) : $first['sentence'],
                ['type' => $type->value, 'url' => '/app/i/planning'],
            )
            : new PushContent(
                sprintf('Absence de %s', $first['absentName']),
                $count > 1 ? sprintf('%d de vos créneaux sont concernés.', $count) : $first['sentence'],
                ['type' => $type->value, 'url' => '/app/s/planning'],
            );
    }

    private static function name(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
