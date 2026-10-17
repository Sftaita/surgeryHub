<?php

namespace App\Activity\Mirror;

use App\Activity\ActivityJournal;
use App\Activity\ActivityPayloadPolicy;
use App\Activity\ActivityRecord;
use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\User;
use App\Entity\UserAuditEvent;
use App\Enum\AuditEventType;
use App\Enum\UserAuditEventType;

/**
 * H1 (D-126) — translates a freshly inserted AuditEvent / UserAuditEvent into the
 * ActivityRecord mirroring it (sourceRef + idempotencyKey = "audit_event:{id}" /
 * "user_audit_event:{id}"). The specialized audit row stays the business proof; this is
 * its timeline projection.
 *
 * Pure mapping: reads only what the audit row (and its already-loaded Mission) carries.
 */
final class AuditEventActivityMapper
{
    /** Human labels for every AuditEventType — used by the generic summary fallback. */
    private const LABELS = [
        'MISSION_DECLARED' => 'Mission déclarée',
        'MISSION_DECLARED_APPROVED' => 'Déclaration de mission approuvée',
        'MISSION_DECLARED_REJECTED' => 'Déclaration de mission refusée',
        'MISSION_CREATED_MANUALLY' => 'Mission créée manuellement',
        'MISSION_DRAFT_UPDATED' => 'Mission en préparation modifiée',
        'MISSION_DRAFT_INSTRUMENTIST_CHANGED' => 'Instrumentiste d\'une mission en préparation modifiée',
        'PLANNING_GENERATED' => 'Planning généré',
        'PLANNING_UNASSIGNED_HANDLED' => 'Missions non assignées traitées',
        'PLANNING_DEPLOYED' => 'Planning déployé',
        'PLANNING_ARCHIVED' => 'Planning archivé',
        'MISSION_CREATED_FROM_PLANNING' => 'Mission créée depuis le planning',
        'MISSION_OPENED_FROM_PLANNING' => 'Mission ouverte depuis le planning',
        'MISSION_ASSIGNED_FROM_PLANNING' => 'Mission assignée depuis le planning',
        'PLANNING_ALERT_ACKNOWLEDGED' => 'Alerte planning prise en compte',
        'PLANNING_ALERT_RESOLVED' => 'Alerte planning résolue',
        'PLANNING_ALERT_IGNORED' => 'Alerte planning ignorée',
        'PLANNING_ALERT_REASSIGNED' => 'Alerte planning résolue par réassignation',
        'PLANNING_ALERT_OPENED_AS_AVAILABLE' => 'Alerte planning résolue : mission ouverte au pool',
        'MISSION_RELEASED_TO_POOL' => 'Mission libérée vers le pool',
        'MISSION_CANCELLED_POST_DEPLOY' => 'Mission annulée',
        'MISSION_REASSIGNED_POST_DEPLOY' => 'Mission réassignée',
        'MISSION_UNCOVERED_ESCALATION_SENT' => 'Escalade : mission non couverte',
        'MISSION_TIME_CHANGED_POST_DEPLOY' => 'Horaire de mission modifié',
        'MISSION_ADDED_POST_DEPLOY' => 'Mission ajoutée après déploiement',
        'MISSION_CLAIMED_FROM_POOL' => 'Mission prise depuis le pool',
        'MISSION_PUBLISHED_TO_POOL' => 'Mission publiée au pool',
        'MISSION_OFFERED_TO_INSTRUMENTIST' => 'Mission proposée à une instrumentiste',
        'MISSION_ASSIGNED_DIRECTLY' => 'Mission attribuée directement',
        'MISSION_OFFER_DECLINED' => 'Proposition de mission refusée',
        'MISSION_ASSIGNMENT_REFUSED_BY_SURGEON' => "Affectation refusée par le chirurgien",
        'MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_DECLARED' => 'Activité déclarée sans instrumentiste',
        'MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_CANCELLED' => 'Déclaration sans instrumentiste annulée',
        'MISSION_ASSIGNMENT_REVIEW_REQUESTED' => 'Intervention demandée sur une affectation',
        'SURGEON_INSTRUMENTIST_INCOMPATIBILITY_CREATED' => 'Incompatibilité chirurgien–instrumentiste créée',
        'SURGEON_INSTRUMENTIST_INCOMPATIBILITY_LIFTED' => 'Incompatibilité chirurgien–instrumentiste levée',
        'PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE' => 'Occurrence neutralisée (absence chirurgien)',
        'PLANNING_OCCURRENCE_INSTRUMENTIST_ABSENCE_NOTICE' => 'Occurrence impactée (absence instrumentiste)',
        'PLANNING_OCCURRENCE_RESTORED_AFTER_ABSENCE' => 'Occurrence restaurée après absence',
        'MISSION_RESTORED_AFTER_SURGEON_ABSENCE' => 'Mission restaurée après absence chirurgien',
        'MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE' => 'Affectation restaurée après absence instrumentiste',
        'MISSION_STARTED' => 'Mission démarrée',
        'MISSION_ENCODING_STARTED' => 'Encodage démarré',
        'MISSION_ENCODING_COMPLETED' => 'Encodage terminé',
        'MISSION_ENCODING_REOPENED' => 'Encodage rouvert',
        'MISSION_ENCODING_VALIDATED' => 'Encodage validé',
        'MISSION_ENCODING_REJECTED' => 'Encodage refusé',
        'MISSION_ENCODING_MANUAL_REMINDER_SENT' => "Rappel d'encodage envoyé",
        'MISSION_EXECUTION_CREATED' => 'Exécution de mission créée',
        'MISSION_EXECUTION_UPDATED' => 'Exécution de mission modifiée',
        'MISSION_EXECUTION_DURATION_CHANGED' => "Durée d'exécution modifiée",
        'MISSION_EXECUTION_DISPUTE_OPENED' => "Contestation d'exécution ouverte",
        'MISSION_EXECUTION_DISPUTE_RESOLVED' => "Contestation d'exécution résolue",
        'MISSION_EXECUTION_DISPUTE_REJECTED' => "Contestation d'exécution rejetée",
        'PRICING_RULE_CREATED' => 'Règle tarifaire créée',
        'PRICING_RULE_SCHEDULED' => 'Règle tarifaire planifiée',
        'PRICING_RULE_REPLACED' => 'Règle tarifaire remplacée',
        'PRICING_RULE_FUTURE_UPDATED' => 'Règle tarifaire future modifiée',
        'PRICING_RULE_FUTURE_CANCELLED' => 'Règle tarifaire future annulée',
        'INSTRUMENTIST_RATE_CREATED' => 'Tarif instrumentiste créé',
        'INSTRUMENTIST_RATE_SCHEDULED' => 'Tarif instrumentiste planifié',
        'INSTRUMENTIST_RATE_REPLACED' => 'Tarif instrumentiste remplacé',
        'INSTRUMENTIST_RATE_FUTURE_UPDATED' => 'Tarif instrumentiste futur modifié',
        'INSTRUMENTIST_RATE_FUTURE_CANCELLED' => 'Tarif instrumentiste futur annulé',
        'FINANCIAL_CALCULATION_CREATED' => 'Calcul financier créé',
        'FINANCIAL_CALCULATION_RECALCULATED' => 'Calcul financier recalculé',
        'FINANCIAL_CALCULATION_APPROVED' => 'Calcul financier approuvé',
        'FINANCIAL_CALCULATION_LOCKED' => 'Calcul financier verrouillé',
        'FINANCIAL_CALCULATION_SUPERSEDED' => 'Calcul financier remplacé',
        'FINANCIAL_CALCULATION_CANCELLED' => 'Calcul financier annulé',
        'FINANCIAL_CALCULATION_FAILED' => 'Calcul financier en échec',
        'FIRM_INVOICE_CREATED_FROM_CALCULATION' => 'Facture firme créée',
        'FIRM_INVOICE_ISSUED' => 'Facture firme émise',
        'FIRM_INVOICE_CANCELLED' => 'Facture firme annulée',
        'INSTRUMENTIST_STATEMENT_CREATED_FROM_CALCULATION' => 'Relevé instrumentiste créé',
        'INSTRUMENTIST_STATEMENT_ISSUED' => 'Relevé instrumentiste émis',
        'INSTRUMENTIST_STATEMENT_CANCELLED' => 'Relevé instrumentiste annulé',
        'DOCUMENT_PAYMENT_RECORDED' => 'Paiement enregistré',
        'DOCUMENT_PARTIALLY_PAID' => 'Document partiellement payé',
        'DOCUMENT_FULLY_PAID' => 'Document entièrement payé',
        'CREDIT_NOTE_CREATED' => 'Note de crédit créée',
        'DEBIT_NOTE_CREATED' => 'Note de débit créée',
        'FINANCIAL_CORRECTION_ISSUED' => 'Correction financière émise',
        'REFUND_RECORDED' => 'Remboursement enregistré',
        'DOCUMENT_NET_BALANCE_CHANGED' => 'Solde du document modifié',
        'MISSION_INTERVENTION_DRAFT_CREATED' => "Brouillon d'intervention créé",
        'MISSION_INTERVENTION_DRAFT_RESOLVED' => "Brouillon d'intervention résolu",
        'MISSION_INTERVENTION_DRAFT_IGNORED_AS_HISTORY' => "Brouillon d'intervention ignoré",
        'MISSION_INTERVENTION_DRAFT_MATERIAL_REASSIGNED' => "Matériel d'un brouillon réaffecté",
        'INTERVENTION_TYPE_MERGED' => "Types d'intervention fusionnés",
        'SURGEON_MISSION_REQUEST_CREATED' => 'Demande de mission chirurgien créée',
        'SURGEON_MISSION_REQUEST_ACCEPTED' => 'Demande de mission chirurgien acceptée',
        'SURGEON_MISSION_REQUEST_REJECTED' => 'Demande de mission chirurgien refusée',
        'ENCODING_ANOMALY_REPORTED' => "Anomalie d'encodage signalée",
        'ENCODING_ANOMALY_RESOLVED' => "Anomalie d'encodage résolue",
        'MATERIAL_ITEM_REQUEST_IGNORED' => 'Proposition de matériel ignorée',
        'ABSENCE_DAY_REMOVED' => "Jour retiré d'une absence",
        'ROOM_SLOT_MADE_AVAILABLE' => 'Salle libérée',
        'ROOM_SLOT_TAKEN_OVER' => 'Salle reprise',
        'ROOM_SLOT_REOPENED' => 'Salle de nouveau disponible',
    ];

    private const USER_LABELS = [
        'USER_CREATED' => 'Compte créé',
        'USER_INVITATION_SENT' => 'Invitation envoyée',
        'USER_INVITATION_RESENT' => 'Invitation renvoyée',
        'USER_INVITATION_COMPLETED' => 'Compte activé',
        'USER_SUSPENDED' => 'Compte suspendu',
        'USER_REACTIVATED' => 'Compte réactivé',
        'USER_ROLE_CHANGED' => 'Rôle modifié',
        'USER_SITE_ADDED' => 'Site ajouté',
        'USER_SITE_REMOVED' => 'Site retiré',
        'ABSENCES_REQUEST_SENT' => 'Demande de congés envoyée',
        'ABSENCES_CONFIRMATION_SENT' => 'Confirmation de congés envoyée',
        'USER_EMAIL_CHANGED' => 'Adresse email modifiée',
    ];

    public function fromAuditEvent(AuditEvent $event): ActivityRecord
    {
        $type    = $event->getEventType();
        $payload = $event->getPayload() ?? [];
        $mission = $event->getMission();
        $actor   = $event->getActor();

        foreach (ActivityPayloadPolicy::FREE_TEXT_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $payload['hasReason'] = ($payload['hasReason'] ?? false) || (is_string($payload[$key]) && trim($payload[$key]) !== '');
                unset($payload[$key]);
            }
        }

        $absenceId = self::intOrNull($payload['causedByAbsenceId'] ?? $payload['absenceId'] ?? null);

        [$subjectType, $subjectId, $subjectLabel] = $mission !== null
            ? ['Mission', $mission->getId(), self::missionLabel($mission)]
            : self::globalSubject($type, $payload, (int) $event->getId());

        return new ActivityRecord(
            eventType: $type,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            summary: $this->auditSummary($type, $actor, $mission, $payload, $absenceId),
            payload: $payload,
            actor: $actor,
            siteId: self::intOrNull($payload['siteId'] ?? null) ?? $mission?->getSite()?->getId(),
            planningVersionId: $mission?->getPlanningVersion()?->getId(),
            missionId: $mission?->getId() ?? self::intOrNull($payload['missionId'] ?? null),
            absenceId: $absenceId,
            userId: self::concernedUserId($type, $payload),
            idempotencyKey: 'audit_event:' . $event->getId(),
            sourceRef: 'audit_event:' . $event->getId(),
            causeAnchors: $absenceId !== null ? ['absence:' . $absenceId] : [],
            // H2 (D-128) — the latest mission event of the operation is the explicit cause of
            // the notifications its MissionLifecycleChangedMessage will produce (the anchor
            // travels in CorrelationStamp to the worker).
            anchorKey: $mission !== null ? 'mission:' . $mission->getId() : null,
        );
    }

    public function fromUserAuditEvent(UserAuditEvent $event): ActivityRecord
    {
        $type   = $event->getEventType();
        $target = $event->getTargetUser();
        $actor  = $event->getActor();
        $label  = self::USER_LABELS[$type->value] ?? $type->value;

        $targetName = ActivityJournal::displayName($target);
        $summary = match ($type) {
            UserAuditEventType::USER_INVITATION_COMPLETED => sprintf('%s a activé son compte', $targetName ?? 'Un utilisateur'),
            UserAuditEventType::USER_ROLE_CHANGED => sprintf('%s a changé le rôle de %s : %s → %s',
                ActivityJournal::displayName($actor) ?? 'Système', $targetName ?? '?',
                $event->getPayload()['oldRole'] ?? '?', $event->getPayload()['newRole'] ?? '?'),
            default => $target !== null
                ? sprintf('%s — %s (par %s)', $label, $targetName, ActivityJournal::displayName($actor) ?? 'Système')
                : sprintf('%s (par %s)', $label, ActivityJournal::displayName($actor) ?? 'Système'),
        };

        return new ActivityRecord(
            eventType: $type,
            subjectType: $target !== null ? 'User' : 'UserAdministration',
            subjectId: $target?->getId(),
            subjectLabel: $targetName,
            summary: $summary,
            payload: $event->getPayload() ?? [],
            actor: $actor,
            userId: $target?->getId(),
            idempotencyKey: 'user_audit_event:' . $event->getId(),
            sourceRef: 'user_audit_event:' . $event->getId(),
        );
    }

    /** @param array<string, mixed> $p */
    private function auditSummary(AuditEventType $type, ?User $actor, ?Mission $mission, array $p, ?int $absenceId): string
    {
        $who   = ActivityJournal::displayName($actor) ?? 'Système';
        $m     = $mission !== null ? sprintf('mission #%d', $mission->getId()) : null;
        $cause = $absenceId !== null ? sprintf(' à la suite de l\'absence #%d', $absenceId) : '';
        $site  = isset($p['siteName']) && is_string($p['siteName']) ? ' — ' . $p['siteName'] : '';

        return match ($type) {
            AuditEventType::MISSION_RELEASED_TO_POOL => sprintf('%s a libéré la %s — %s → OPEN%s%s',
                $who, $m ?? 'mission', $p['previousStatus'] ?? 'ASSIGNED', $cause, self::fromWhom($p)) . $site,
            AuditEventType::MISSION_CANCELLED_POST_DEPLOY => sprintf('Mission #%d annulée%s — %s → CANCELLED (par %s)%s',
                $mission?->getId() ?? 0, $cause, $p['previousStatus'] ?? '?', $who, self::fromWhom($p)) . $site,
            AuditEventType::MISSION_CLAIMED_FROM_POOL => sprintf('%s a pris la %s depuis le pool',
                $p['instrumentistName'] ?? $who, $m ?? 'mission') . $site,
            // D-151 — the real origin, for the managers' journal (never shown to the instrumentist).
            AuditEventType::MISSION_ASSIGNMENT_REFUSED_BY_SURGEON => sprintf("%s a refusé l'affectation de %s à la %s (refus ponctuel) — ASSIGNED → OPEN",
                $who, $p['fromInstrumentistName'] ?? 'l\'instrumentiste', $m ?? 'mission') . $site,
            // D-155 — activité exécutée sans instrumentiste.
            AuditEventType::MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_DECLARED => sprintf("%s a déclaré la %s réalisée sans instrumentiste — OPEN → ENCODING_IN_PROGRESS",
                $who, $m ?? 'mission') . $site,
            AuditEventType::MISSION_EXECUTED_WITHOUT_INSTRUMENTIST_CANCELLED => sprintf("%s a annulé la déclaration « réalisée sans instrumentiste » de la %s — ENCODING_IN_PROGRESS → OPEN",
                $who, $m ?? 'mission') . $site,
            AuditEventType::MISSION_ASSIGNMENT_REVIEW_REQUESTED => sprintf("%s demande l'intervention du manager sur l'affectation de %s à la %s",
                $who, $p['instrumentistName'] ?? 'l\'instrumentiste', $m ?? 'mission') . $site,
            // D-152 — a pair of people, never a mission; the managers' journal only.
            AuditEventType::SURGEON_INSTRUMENTIST_INCOMPATIBILITY_CREATED => sprintf('%s a rendu %s incompatible avec %s (%s)%s',
                $who, $p['instrumentistName'] ?? 'un instrumentiste', $p['surgeonName'] ?? 'un chirurgien',
                match ($p['origin'] ?? null) { 'SURGEON_REFUSAL' => 'refus durable', 'SURGEON' => 'blocage par le chirurgien', default => 'décision manager' },
                is_array($p['conflictMissionIds'] ?? null) && $p['conflictMissionIds'] !== []
                    ? sprintf(' — %d mission(s) future(s) à traiter', count($p['conflictMissionIds'])) : ''),
            AuditEventType::SURGEON_INSTRUMENTIST_INCOMPATIBILITY_LIFTED => sprintf("%s a levé l'incompatibilité entre %s et %s",
                $who, $p['surgeonName'] ?? 'un chirurgien', $p['instrumentistName'] ?? 'un instrumentiste'),
            AuditEventType::MISSION_REASSIGNED_POST_DEPLOY => sprintf('%s a réassigné la %s : %s → %s%s',
                $who, $m ?? 'mission', $p['fromInstrumentistName'] ?? 'personne', $p['toInstrumentistName'] ?? 'personne', $cause) . $site,
            AuditEventType::MISSION_TIME_CHANGED_POST_DEPLOY => sprintf('%s a modifié l\'horaire de la %s : %s → %s',
                $who, $m ?? 'mission', self::hm($p['fromStartAt'] ?? null), self::hm($p['toStartAt'] ?? null)) . $site,
            AuditEventType::ROOM_SLOT_MADE_AVAILABLE => sprintf('Salle de %s libérée le %s%s',
                $p['originalSurgeonName'] ?? 'un chirurgien', self::dmy($p['occurrenceDate'] ?? null), $cause) . $site,
            AuditEventType::ROOM_SLOT_TAKEN_OVER => sprintf('%s a repris la salle du %s', $who, self::dmy($p['occurrenceDate'] ?? null)) . $site,
            AuditEventType::PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE => sprintf('Occurrence du %s de %s neutralisée%s',
                self::dmy($p['occurrenceDate'] ?? null), $p['surgeonName'] ?? 'un chirurgien', $cause),
            // H5b (D-132) — readable sentences (§29) for the manual DRAFT work, pricing,
            // financial documents and payments; the financial detail stays in its source.
            AuditEventType::MISSION_CREATED_MANUALLY => sprintf('%s a créé manuellement la %s du %s (%s)%s',
                $who, $m ?? 'mission', self::dmy($p['startAt'] ?? null), $p['surgeonName'] ?? 'chirurgien inconnu',
                isset($p['instrumentistName']) && $p['instrumentistName'] !== '' ? ' avec ' . $p['instrumentistName'] : '') . $site,
            AuditEventType::MISSION_DRAFT_UPDATED => sprintf('%s a modifié la %s en préparation : %s → %s',
                $who, $m ?? 'mission', self::dmyHm($p['fromStartAt'] ?? null), self::dmyHm($p['toStartAt'] ?? null)) . $site,
            AuditEventType::MISSION_DRAFT_INSTRUMENTIST_CHANGED => sprintf('%s a changé l\'instrumentiste de la %s en préparation : %s → %s',
                $who, $m ?? 'mission', $p['fromInstrumentistName'] ?? 'aucune', $p['toInstrumentistName'] ?? 'aucune') . $site,
            AuditEventType::PRICING_RULE_CREATED, AuditEventType::PRICING_RULE_SCHEDULED => sprintf('%s a créé le tarif firme %s, applicable %s',
                $who, self::pricingScope($p), self::fromText($p['validFrom'] ?? null)),
            AuditEventType::PRICING_RULE_REPLACED => sprintf('Tarif firme %s modifié par %s, nouvelle version applicable à partir du %s',
                self::pricingScope($p), $who, self::dmy($p['effectiveFrom'] ?? null)),
            AuditEventType::PRICING_RULE_FUTURE_UPDATED => sprintf('%s a modifié la version future du tarif firme %s', $who, self::pricingScope($p)),
            AuditEventType::PRICING_RULE_FUTURE_CANCELLED => sprintf('%s a annulé la version future du tarif firme %s', $who, self::pricingScope($p)),
            AuditEventType::INSTRUMENTIST_RATE_CREATED, AuditEventType::INSTRUMENTIST_RATE_SCHEDULED => sprintf('%s a créé le tarif instrumentiste (%s) de %s, applicable %s',
                $who, $p['rateType'] ?? '?', $p['instrumentistName'] ?? '?', self::fromText($p['validFrom'] ?? null)),
            AuditEventType::INSTRUMENTIST_RATE_REPLACED => sprintf('Tarif instrumentiste (%s) de %s modifié par %s, nouvelle version applicable à partir du %s',
                $p['rateType'] ?? '?', $p['instrumentistName'] ?? '?', $who, self::dmy($p['effectiveFrom'] ?? null)),
            AuditEventType::INSTRUMENTIST_RATE_FUTURE_UPDATED => sprintf('%s a modifié la version future du tarif instrumentiste (%s) de %s', $who, $p['rateType'] ?? '?', $p['instrumentistName'] ?? '?'),
            AuditEventType::INSTRUMENTIST_RATE_FUTURE_CANCELLED => sprintf('%s a annulé la version future du tarif instrumentiste (%s) de %s', $who, $p['rateType'] ?? '?', $p['instrumentistName'] ?? '?'),
            AuditEventType::FIRM_INVOICE_ISSUED => sprintf('Facture firme %s émise (par %s)', self::docNumber($p, 'firmInvoiceId'), $who),
            AuditEventType::FIRM_INVOICE_CANCELLED => sprintf('Facture firme %s annulée (par %s)', self::docNumber($p, 'firmInvoiceId'), $who),
            AuditEventType::INSTRUMENTIST_STATEMENT_ISSUED => sprintf('Décompte instrumentiste %s émis (par %s)', self::docNumber($p, 'instrumentistStatementId'), $who),
            AuditEventType::INSTRUMENTIST_STATEMENT_CANCELLED => sprintf('Décompte instrumentiste %s annulé (par %s)', self::docNumber($p, 'instrumentistStatementId'), $who),
            AuditEventType::DOCUMENT_PAYMENT_RECORDED => sprintf('Paiement du document %s enregistré (par %s)', self::docNumber($p, 'documentId'), $who),
            AuditEventType::REFUND_RECORDED => sprintf('Remboursement sur le document %s enregistré (par %s)', self::docNumber($p, 'documentId'), $who),
            // D-153 — before → after of the real hours, when the payload carries them.
            AuditEventType::MISSION_EXECUTION_UPDATED, AuditEventType::MISSION_EXECUTION_DURATION_CHANGED
                => array_key_exists('previousActualStartAt', $p)
                    ? sprintf('%s a corrigé les heures réelles de la %s : %s → %s', $who, $m ?? 'mission',
                        self::hoursRange($p['previousActualStartAt'] ?? null, $p['previousActualEndAt'] ?? null, $p['previousActualDurationMinutes'] ?? null),
                        self::hoursRange($p['actualStartAt'] ?? null, $p['actualEndAt'] ?? null, $p['actualDurationMinutes'] ?? null))
                    : sprintf('%s%s (par %s)', self::LABELS[$type->value] ?? $type->value, $m !== null ? ' — ' . $m : '', $who),
            default => sprintf('%s%s%s (par %s)', self::LABELS[$type->value] ?? $type->value,
                $m !== null ? ' — ' . $m : '', $cause, $who) . $site,
        };
    }

    /** @param array<string, mixed> $p — "« Arthrodèse 2 niveaux » (Firme X)" from the audit scope keys */
    private static function pricingScope(array $p): string
    {
        $what = $p['interventionTypeCode'] ?? $p['materialItemLabel'] ?? $p['choiceOptionLabel'] ?? $p['ruleType'] ?? '?';

        return sprintf('« %s »%s', $what, isset($p['firmName']) && $p['firmName'] !== '' ? ' (' . $p['firmName'] . ')' : '');
    }

    /** @param array<string, mixed> $p */
    private static function docNumber(array $p, string $idKey): string
    {
        $number = $p['number'] ?? $p['documentNumber'] ?? null;

        return is_string($number) && $number !== '' ? $number : '#' . (string) ($p[$idKey] ?? '?');
    }

    private static function dmyHm(mixed $iso): string
    {
        if (!is_string($iso) || $iso === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($iso))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /** @param array<string, mixed> $p */
    private static function fromWhom(array $p): string
    {
        $name = $p['fromInstrumentistName'] ?? null;

        return is_string($name) && $name !== '' ? sprintf(' — instrumentiste retirée : %s', $name) : '';
    }

    /**
     * @param array<string, mixed> $p
     * @return array{0: string, 1: ?int, 2: ?string}
     */
    private static function globalSubject(AuditEventType $type, array $p, int $auditId): array
    {
        // H5b (D-132) — a payment / refund on a document belongs to that document's timeline
        // (the same subject as its issue and its sends), not to an abstract PayableDocument.
        $documentSubjects = ['FIRM_INVOICE' => 'FirmInvoice', 'INSTRUMENTIST_STATEMENT' => 'InstrumentistStatement'];
        if (isset($p['documentId'], $p['documentType']) && is_int($p['documentId']) && isset($documentSubjects[$p['documentType']])) {
            return [$documentSubjects[$p['documentType']], $p['documentId'], sprintf('%s #%d', $documentSubjects[$p['documentType']], $p['documentId'])];
        }
        $candidates = [
            // H5b (D-132) — the keys the financial services actually write (firmInvoiceId /
            // instrumentistStatementId): issue, cancel, creation now land on the document.
            'firmInvoiceId'            => 'FirmInvoice',
            'instrumentistStatementId' => 'InstrumentistStatement',
            'roomSlotId'              => 'ReleasedOperatingRoomSlot',
            'pricingRuleId'           => 'PricingRule',
            'instrumentistRateId'     => 'InstrumentistRate',
            'surgeonMissionRequestId' => 'SurgeonMissionRequest',
            'financialCalculationId'  => 'FinancialCalculation',
            'invoiceId'               => 'FirmInvoice',
            'statementId'             => 'InstrumentistStatement',
            'documentId'              => 'PayableDocument',
            'postId'                  => 'SurgeonSchedulePost',
            'absenceId'               => 'Absence',
        ];
        foreach ($candidates as $key => $subjectType) {
            if (isset($p[$key]) && is_int($p[$key])) {
                return [$subjectType, $p[$key], sprintf('%s #%d', $subjectType, $p[$key])];
            }
        }

        return ['AuditEvent', $auditId, self::LABELS[$type->value] ?? $type->value];
    }

    /** @param array<string, mixed> $p */
    private static function concernedUserId(AuditEventType $type, array $p): ?int
    {
        return self::intOrNull(match ($type) {
            AuditEventType::MISSION_RELEASED_TO_POOL,
            AuditEventType::MISSION_ASSIGNMENT_REFUSED_BY_SURGEON,
            AuditEventType::MISSION_CANCELLED_POST_DEPLOY => $p['fromInstrumentistId'] ?? null,
            AuditEventType::MISSION_REASSIGNED_POST_DEPLOY => $p['toInstrumentistId'] ?? $p['fromInstrumentistId'] ?? null,
            default => $p['instrumentistId'] ?? $p['originalSurgeonId'] ?? $p['surgeonId'] ?? null,
        });
    }

    public static function missionLabel(Mission $mission): string
    {
        $start = $mission->getStartAt();

        return sprintf('Mission #%d%s%s', $mission->getId(),
            $start !== null ? ' — ' . $start->format('d/m/Y H:i') : '',
            $mission->getSite() !== null ? ' — ' . $mission->getSite()->getName() : '');
    }

    /** A version without validFrom applies since always (D-072) — never a "?" in a sentence. */
    private static function fromText(mixed $date): string
    {
        return is_string($date) && $date !== '' ? 'à partir du ' . self::dmy($date) : 'sans date de début';
    }

    private static function dmy(mixed $date): string
    {
        if (!is_string($date) || $date === '') {
            return '?';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return $d !== false ? $d->format('d/m/Y') : $date;
    }

    /** D-153 — "08:00–12:30 (270 min)", or "aucune" when nothing was encoded. */
    private static function hoursRange(mixed $start, mixed $end, mixed $minutes): string
    {
        $time = static function (mixed $iso): ?string {
            if (!is_string($iso) || $iso === '') {
                return null;
            }
            try {
                return (new \DateTimeImmutable($iso))->format('H:i');
            } catch (\Exception) {
                return $iso;
            }
        };
        $s = $time($start);
        $e = $time($end);
        $range = $s !== null || $e !== null ? sprintf('%s–%s', $s ?? '?', $e ?? '?') : null;
        $duration = is_int($minutes) ? sprintf('%d min', $minutes) : null;

        return match (true) {
            $range !== null && $duration !== null => sprintf('%s (%s)', $range, $duration),
            $range !== null => $range,
            $duration !== null => $duration,
            default => 'aucune',
        };
    }

    private static function hm(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '?';
        }
        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (\Exception) {
            return $value;
        }
    }

    private static function intOrNull(mixed $v): ?int
    {
        return is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : null);
    }
}
