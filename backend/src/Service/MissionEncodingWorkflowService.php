<?php

namespace App\Service;

use App\Entity\FinancialCalculation;
use App\Entity\Mission;
use App\Entity\MissionEncodingComment;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Message\MissionLifecycleChangedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 7 (D-070) — cycle de vie de l'encodage :
 *
 *   ASSIGNED/IN_PROGRESS ──start──▶ ENCODING_IN_PROGRESS ──complete──▶ SUBMITTED
 *                                                                          │
 *                                                          ┌───validate───┤───reject (commentaire requis)──┐
 *                                                          ▼                                               ▼
 *                                                      VALIDATED                                ENCODING_IN_PROGRESS
 *                                                          │
 *                                                      reopen (commentaire optionnel)
 *                                                          ▼
 *                                                  ENCODING_IN_PROGRESS
 *
 * Chaque transition : vérifie l'état de départ (ConflictHttpException sinon, même idiome
 * que MissionService::approveDeclared()/rejectDeclared()), mute le statut, écrit l'AuditEvent,
 * flush (R-05), puis dispatch MissionLifecycleChangedMessage — géré par le handler existant
 * via son branchement "unhandled changeType — forward-compatible skip" (aucune notification
 * réelle branchée dans ce lot, volontairement).
 *
 * Pas de verrou pessimiste ici (contrairement à claim()/start() D-064) : ce sont des actions
 * humaines explicites (clic manager/instrumentiste), pas une tâche planifiée qui se
 * chevauche — le risque de double-transition concurrente est faible, et la vérification de
 * l'état de départ protège déjà contre un double traitement silencieux (la seconde tentative
 * trouve un statut qui ne correspond plus et échoue proprement en 409).
 */
final class MissionEncodingWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly AuditService $audit,
        private readonly MessageBusInterface $bus,
    ) {}

    /** ASSIGNED|IN_PROGRESS → ENCODING_IN_PROGRESS. */
    public function start(Mission $mission, User $actor): Mission
    {
        $this->encodingGuard->assertEncodingAllowed($mission, $actor);

        if (!in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)) {
            throw new ConflictHttpException('Mission must be ASSIGNED or IN_PROGRESS to start encoding');
        }

        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $mission->setEncodingStartedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_STARTED, $payload);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_STARTED, $actor, $payload);

        return $mission;
    }

    /**
     * DECLARED|ASSIGNED|IN_PROGRESS|ENCODING_IN_PROGRESS|SUBMITTED → SUBMITTED.
     * "Mon encodage est terminé" — l'instrumentiste peut la déclarer sans être passé par
     * start() explicitement (DECLARED/ASSIGNED/IN_PROGRESS acceptés directement, comportement
     * préexistant conservé à l'identique) ; SUBMITTED→SUBMITTED reste idempotent (liberté
     * instrumentiste déjà établie avant ce lot).
     *
     * C'est l'implémentation réelle derrière POST /api/missions/{id}/submit (legacy, inchangé
     * pour compatibilité) et POST /api/missions/{id}/encoding/complete (Lot 7) — un seul et
     * même point d'entrée métier, jamais dupliqué.
     */
    public function complete(Mission $mission, User $actor): Mission
    {
        $this->encodingGuard->assertEncodingAllowed($mission, $actor);

        if (!in_array($mission->getStatus(), [
            MissionStatus::DECLARED,
            MissionStatus::ASSIGNED,
            MissionStatus::IN_PROGRESS,
            MissionStatus::ENCODING_IN_PROGRESS,
            MissionStatus::SUBMITTED,
        ], true)) {
            throw new ConflictHttpException('Mission cannot be submitted from its current status');
        }

        $mission->setStatus(MissionStatus::SUBMITTED);
        $mission->setSubmittedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_COMPLETED, $payload);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_COMPLETED, $actor, $payload);

        return $mission;
    }

    /** SUBMITTED → VALIDATED (verrouille l'encodage — encodingLockedAt, lu par MissionEncodingGuard). */
    public function validate(Mission $mission, User $actor): Mission
    {
        if ($mission->getStatus() !== MissionStatus::SUBMITTED) {
            throw new ConflictHttpException('Mission must be SUBMITTED to validate its encoding');
        }

        $mission->setStatus(MissionStatus::VALIDATED);
        $mission->setEncodingLockedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_VALIDATED, $payload);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_VALIDATED, $actor, $payload);

        return $mission;
    }

    /**
     * SUBMITTED → ENCODING_IN_PROGRESS, commentaire obligatoire (matériel manquant, quantité
     * incorrecte, mauvaise firme, intervention incomplète...) — historisé sur la mission via
     * MissionEncodingComment, jamais perdu.
     */
    public function reject(Mission $mission, User $actor, string $comment): Mission
    {
        if ($mission->getStatus() !== MissionStatus::SUBMITTED) {
            throw new ConflictHttpException('Mission must be SUBMITTED to reject its encoding');
        }

        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $this->addComment($mission, $actor, $comment);

        $payload = array_merge($this->actorPayload($actor), ['comment' => $comment]);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_REJECTED, $payload);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_REJECTED, $actor, $payload);

        return $mission;
    }

    /**
     * VALIDATED → ENCODING_IN_PROGRESS, commentaire optionnel. Déverrouille explicitement
     * (encodingLockedAt = null) — c'est le seul chemin qui le fait ; jamais depuis CLOSED
     * (terminal, message d'erreur dédié pour ne pas confondre avec un simple mauvais état).
     *
     * EPIC Exécution & Valorisation, Lot 3 (D-073) — politique de réouverture à seuil :
     * un FinancialCalculation LOCKED (intégré à un document financier interne) bloque
     * désormais aussi la réouverture, en plus du statut CLOSED. Pas encore de calcul
     * seulement CALCULATED/APPROVED (jamais LOCKED) : la réouverture reste libre comme
     * avant ce lot — rien ne change tant qu'aucun engagement financier n'existe.
     */
    public function reopen(Mission $mission, User $actor, ?string $comment = null): Mission
    {
        if ($mission->getStatus() === MissionStatus::CLOSED) {
            throw new ConflictHttpException('A CLOSED mission can never be reopened');
        }

        if ($mission->getStatus() !== MissionStatus::VALIDATED) {
            throw new ConflictHttpException('Mission must be VALIDATED to reopen its encoding');
        }

        if ($this->hasLockedFinancialCalculation($mission)) {
            throw new ConflictHttpException('This mission has a LOCKED financial calculation and can no longer be reopened — a future correction workflow will be needed.');
        }

        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $mission->setEncodingLockedAt(null);

        if ($comment !== null && trim($comment) !== '') {
            $this->addComment($mission, $actor, $comment);
        }

        $payload = array_merge($this->actorPayload($actor), ['comment' => $comment]);
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_REOPENED, $payload);
        $this->em->flush();

        // "notifier l'instrumentiste (préparer le mécanisme, même si la notification est
        // réalisée plus tard)" — dispatché comme les 4 autres transitions ; le handler
        // n'a pas encore de cas ENCODING_REOPENED, il no-op proprement (branche par défaut).
        $this->dispatch($mission, MissionChangeType::ENCODING_REOPENED, $actor, $payload);

        return $mission;
    }

    /**
     * Point d'entrée unique pour le futur moteur de facturation (Lot 8+, non implémenté ici) :
     * seul VALIDATED est facturable, jamais une mission en cours. Ne rien ajouter d'autre
     * ici tant que le moteur réel n'existe pas — c'est délibérément le seul contrat exposé.
     */
    public function isBillable(Mission $mission): bool
    {
        return $mission->getStatus() === MissionStatus::VALIDATED;
    }

    /** @return Mission[] */
    public function findMissionsReadyForBilling(): array
    {
        return $this->em->getRepository(Mission::class)->findBy(['status' => MissionStatus::VALIDATED]);
    }

    /** D-073 (Lot 3) — requête directe, pas de dépendance au FinancialCalculationService complet pour ce seul contrôle. */
    private function hasLockedFinancialCalculation(Mission $mission): bool
    {
        return $this->em->getRepository(FinancialCalculation::class)->findOneBy([
            'mission' => $mission,
            'status' => FinancialCalculationStatus::LOCKED,
        ]) !== null;
    }

    private function addComment(Mission $mission, User $author, string $comment): void
    {
        $entity = new MissionEncodingComment();
        $entity->setMission($mission)->setAuthor($author)->setComment($comment);
        $this->em->persist($entity);

        // Keeps the in-memory collection consistent within the same request — Doctrine
        // does not sync the inverse side automatically from the owning side's FK alone.
        $mission->getEncodingComments()->add($entity);
    }

    /** @return array{actorId: int, actorName: string} */
    private function actorPayload(User $actor): array
    {
        return [
            'actorId'   => $actor->getId(),
            'actorName' => trim(($actor->getFirstname() ?? '') . ' ' . ($actor->getLastname() ?? '')),
        ];
    }

    private function dispatch(Mission $mission, MissionChangeType $changeType, User $actor, array $payload): void
    {
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: $changeType,
            actorId:    $actor->getId(),
            payload:    $payload,
            occurredAt: new \DateTimeImmutable(),
        ));
    }
}
