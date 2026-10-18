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
use App\Enum\MissionType;
use App\Exception\PrestationConflictException;
use App\Message\MissionLifecycleChangedMessage;
use App\Service\Prestation\PrestationGroupResolver;
use App\Service\Prestation\PrestationGrouping;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lot 7 (D-070) — cycle de vie de l'encodage :
 *
 *   ASSIGNED/IN_PROGRESS ──start──▶ ENCODING_IN_PROGRESS ──complete (commentaire requis si BLOCK et 0 matériel)──▶ SUBMITTED
 *                                                                          │
 *                                                          ┌───validate───┤───reject (commentaire requis)──┐
 *                                                          ▼                                               ▼
 *                                                      VALIDATED                                ENCODING_IN_PROGRESS
 *                                                          │
 *                                                      reopen (motif requis, D-148)
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
        private readonly EncodingCompletenessService $completeness,
        private readonly EncodingContentCompletenessService $contentCompleteness,
        private readonly PrestationGroupResolver $prestationGroups,
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
     *
     * $comment : requis uniquement pour une mission MissionType::BLOCK n'ayant aucune ligne
     * de matériel *active* encodée (toutes interventions confondues) — décrit alors ce qui a
     * été réalisé en l'absence de matériel. Une mission MissionType::CONSULTATION n'attend
     * jamais de matériel : jamais de commentaire exigé pour ce type, quel que soit le nombre
     * de lignes. Le total de lignes est toujours recalculé côté serveur
     * (countActiveMaterialLines(), jamais un flag envoyé par le client) : source de
     * vérité unique, jamais de confiance aveugle dans ce que déclare le frontend.
     *
     * $submittedWithoutMaterial est figé à CHAQUE appel (jamais dérivé après coup) : une
     * resoumission ultérieure avec du matériel repasse ce flag à false — le commentaire
     * précédent reste en base pour traçabilité mais n'est plus la justification de la
     * soumission courante (voir Mission::$noMaterialComment).
     */
    public function complete(Mission $mission, User $actor, ?string $comment = null): Mission
    {
        // D-156 — une mission d'une prestation multi-salles se soumet avec ses missions
        // sœurs (completePrestation), jamais seule : aucune mission soumise et l'autre oubliée.
        $group = $this->prestationGroups->groupOf($mission);
        if (count($group) > 1) {
            throw new PrestationConflictException(
                PrestationConflictException::PRESTATION_REQUIRES_COMMON_SUBMIT,
                'Cette mission fait partie d\'une prestation multi-salles : soumettez la prestation complète.',
                ['missionId' => $mission->getId(), 'prestationMissionIds' => PrestationGrouping::ids($group)],
            );
        }

        $this->assertCompletable($mission, $actor, $comment);
        $payload = $this->applyComplete($mission, $actor, $comment);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_COMPLETED, $actor, $payload);

        return $mission;
    }

    /**
     * D-156 — soumission commune d'une prestation regroupée multi-salles : TOUTES les
     * missions à soumettre sont contrôlées (mêmes règles que complete(), aucune exception)
     * avant qu'aucune ne change de statut, puis toutes passent SUBMITTED dans la même
     * transaction — jamais une mission soumise et l'autre oubliée. Idempotent : une mission
     * déjà SUBMITTED est laissée telle quelle. La justification « sans matériel » unique
     * n'est posée que sur les missions BLOC sans matériel actif (D-080). Les messages de
     * cycle de vie partent après le commit, un par mission (traçabilité individuelle).
     *
     * @param \Closure(): list<Mission> $lockAndResolve résout la prestation SOUS VERROU, dans
     *        cette même transaction (contrôles de composition/empreinte de l'appelant inclus)
     * @return list<Mission> les missions effectivement soumises par cet appel
     */
    public function completePrestation(\Closure $lockAndResolve, User $actor, ?string $noMaterialComment, string $prestationKey): array
    {
        $submitted = $this->em->wrapInTransaction(function () use ($lockAndResolve, $actor, $noMaterialComment, $prestationKey): array {
            $missions = $lockAndResolve();
            $toSubmit = array_values(array_filter($missions, static fn (Mission $m) => $m->getStatus() !== MissionStatus::SUBMITTED));
            foreach ($toSubmit as $mission) {
                $this->assertCompletable($mission, $actor, $this->justificationFor($mission, $noMaterialComment));
            }

            $applied = [];
            foreach ($toSubmit as $mission) {
                $payload = $this->applyComplete($mission, $actor, $this->justificationFor($mission, $noMaterialComment), [
                    'prestationKey' => $prestationKey,
                    'prestationMissionIds' => array_map(static fn (Mission $m) => $m->getId(), $missions),
                ]);
                $applied[] = [$mission, $payload];
            }
            $this->em->flush();

            return $applied;
        });

        foreach ($submitted as [$mission, $payload]) {
            $this->dispatch($mission, MissionChangeType::ENCODING_COMPLETED, $actor, $payload);
        }

        return array_map(static fn (array $pair) => $pair[0], $submitted);
    }

    /** Justification « sans matériel » : uniquement sur une mission BLOC sans matériel actif. */
    private function justificationFor(Mission $mission, ?string $comment): ?string
    {
        return $mission->getType() === MissionType::BLOCK && $this->countActiveMaterialLines($mission) === 0
            ? $comment
            : null;
    }

    /** Tous les contrôles de complete(), sans aucune écriture. */
    private function assertCompletable(Mission $mission, User $actor, ?string $comment): void
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

        // D-143 — heures réelles (début ET fin cohérents) obligatoires : jamais les horaires
        // planifiés, jamais une durée seule. Seule la transition est refusée, rien n'est perdu.
        $this->completeness->assertSubmittable(
            $mission,
            'Il reste une petite étape avant de terminer : pensez à renseigner vos heures prestées (début et fin).',
        );

        // D-146 bis — chaque intervention doit être complète (firme, réponse délégué,
        // système choisi, matériel compatible) : jamais une mission soumise puis bloquée
        // au calcul financier. Les demandes catalogue en attente ne bloquent pas la
        // soumission (le manager les traite pendant la phase SUBMITTED).
        $this->contentCompleteness->assertComplete(
            $mission,
            false,
            "Certaines interventions sont incomplètes : complétez-les avant de terminer l'encodage.",
        );

        $trimmedComment = $comment !== null ? trim($comment) : null;
        $requiresJustification = $mission->getType() === MissionType::BLOCK && $this->countActiveMaterialLines($mission) === 0;

        if ($requiresJustification && ($trimmedComment === null || $trimmedComment === '')) {
            throw new UnprocessableEntityHttpException(
                "Aucun matériel n'a été encodé pour cette mission : décrivez les interventions réalisées avant de clôturer.",
            );
        }
    }

    /**
     * Les écritures de complete() (statut, horodatage, justification, audit), sans flush.
     *
     * @param array<string,mixed> $extraPayload
     * @return array<string,mixed> payload de l'AuditEvent / du message
     */
    private function applyComplete(Mission $mission, User $actor, ?string $comment, array $extraPayload = []): array
    {
        $trimmedComment = $comment !== null ? trim($comment) : null;
        $hasNoActiveMaterial = $this->countActiveMaterialLines($mission) === 0;

        $mission->setSubmittedWithoutMaterial($hasNoActiveMaterial);
        if ($trimmedComment !== null && $trimmedComment !== '') {
            $mission->setNoMaterialComment($trimmedComment);
        }

        $mission->setStatus(MissionStatus::SUBMITTED);
        $mission->setSubmittedAt(new \DateTimeImmutable());

        $payload = $this->actorPayload($actor) + $extraPayload;
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_COMPLETED, $payload);

        return $payload;
    }

    /**
     * Compte les lignes de matériel réellement significatives pour décider si la mission
     * a "du matériel encodé". Ignore les lignes à quantité nulle ou négative (aucune
     * contrainte Assert\Positive côté DTO aujourd'hui — cf. MaterialLineCreateRequest/
     * MaterialLineUpdateRequest — donc quantity=0 est persistable en base et ne doit pas
     * compter comme du matériel réellement utilisé).
     *
     * MaterialLine n'a ni suppression logique (DELETE fait un vrai
     * $this->em->remove(), voir MaterialLineController), ni flag `active` propre à la
     * ligne (seul MaterialItem, le catalogue, en a un — qui ne doit jamais invalider
     * rétroactivement une ligne déjà utilisée), ni notion de version/superseded (contrairement
     * à PricingRule/InstrumentistRate, D-072) : ces trois filtres ne s'appliquent donc pas à
     * cette entité, volontairement pas simulés ici.
     */
    private function countActiveMaterialLines(Mission $mission): int
    {
        $count = 0;
        foreach ($mission->getMaterialLines() as $line) {
            if ((float) $line->getQuantity() > 0) {
                ++$count;
            }
        }

        return $count;
    }

    /** SUBMITTED → VALIDATED (verrouille l'encodage — encodingLockedAt, lu par MissionEncodingGuard). */
    public function validate(Mission $mission, User $actor): Mission
    {
        if ($mission->getStatus() !== MissionStatus::SUBMITTED) {
            throw new ConflictHttpException('Mission must be SUBMITTED to validate its encoding');
        }

        // D-143 — une mission soumise SANS aucune heure réelle (cas historiques, D-143 §audit)
        // ne peut pas être validée — donc valorisée — sur le planifié par défaut : renvoyer
        // l'encodage à l'instrumentiste (reject) ou saisir les heures (source MANAGER) d'abord.
        // Une durée seule déclarée avant D-143 reste validable.
        $this->completeness->assertHasDeclaredHours(
            $mission,
            "Heures prestées manquantes : renseignez-les ou renvoyez l'encodage à l'instrumentiste avant de valider.",
        );

        // D-146 bis — jamais une validation définitive d'un contenu incomplet (intervention
        // sans firme, réponse obligatoire absente, matériel incompatible) ni avec une
        // demande catalogue encore en attente : la mission serait verrouillée alors qu'une
        // intervention ou une ligne de matériel peut encore lui être ajoutée.
        $this->contentCompleteness->assertComplete(
            $mission,
            true,
            "Encodage incomplet : complétez les interventions et traitez les demandes en attente avant de valider.",
        );

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
     *
     * D-148 — « Renvoyer en correction » (Suivi des encodages) : seul le statut change.
     * Interventions, matériel, heures, affectation, planning et calculs financiers restent
     * intacts ; l'instrumentiste est prévenue par le handler (ENCODING_CORRECTION_REQUESTED).
     */
    public function reject(Mission $mission, User $actor, string $comment): Mission
    {
        if ($mission->getStatus() !== MissionStatus::SUBMITTED) {
            throw new ConflictHttpException('Mission must be SUBMITTED to reject its encoding');
        }

        $comment = $this->requireReason($comment);
        $from = $mission->getStatus();
        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $this->addComment($mission, $actor, $comment);

        $payload = array_merge($this->actorPayload($actor), ['comment' => $comment], $this->transitionPayload($from, $mission));
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_REJECTED, $payload);
        $this->em->flush();

        $this->dispatch($mission, MissionChangeType::ENCODING_REJECTED, $actor, $payload);

        return $mission;
    }

    /**
     * VALIDATED → ENCODING_IN_PROGRESS, motif obligatoire depuis D-148 (optionnel en D-070) :
     * l'instrumentiste doit savoir ce qu'elle corrige. Déverrouille explicitement
     * (encodingLockedAt = null) — c'est le seul chemin qui le fait ; jamais depuis CLOSED
     * (terminal, message d'erreur dédié pour ne pas confondre avec un simple mauvais état).
     *
     * EPIC Exécution & Valorisation, Lot 3 (D-073) — politique de réouverture à seuil :
     * un FinancialCalculation LOCKED (intégré à un document financier interne) bloque
     * désormais aussi la réouverture, en plus du statut CLOSED. Pas encore de calcul
     * seulement CALCULATED/APPROVED (jamais LOCKED) : la réouverture reste libre comme
     * avant ce lot — rien ne change tant qu'aucun engagement financier n'existe.
     */
    public function reopen(Mission $mission, User $actor, string $comment): Mission
    {
        if ($mission->getStatus() === MissionStatus::CLOSED) {
            throw new ConflictHttpException(MissionEncodingCorrectionPolicy::CLOSED_MESSAGE);
        }

        if ($mission->getStatus() !== MissionStatus::VALIDATED) {
            throw new ConflictHttpException('Mission must be VALIDATED to reopen its encoding');
        }

        // Requête directe (jamais la collection en mémoire) : c'est l'autorité au moment de
        // la transition ; MissionEncodingCorrectionPolicy n'en est que le reflet affiché.
        if ($this->hasLockedFinancialCalculation($mission)) {
            throw new ConflictHttpException(MissionEncodingCorrectionPolicy::FINANCIAL_LOCK_MESSAGE);
        }

        $comment = $this->requireReason($comment);
        $from = $mission->getStatus();
        $mission->setStatus(MissionStatus::ENCODING_IN_PROGRESS);
        $mission->setEncodingLockedAt(null);
        $this->addComment($mission, $actor, $comment);

        $payload = array_merge($this->actorPayload($actor), ['comment' => $comment], $this->transitionPayload($from, $mission));
        $this->audit->record($mission, $actor, AuditEventType::MISSION_ENCODING_REOPENED, $payload);
        $this->em->flush();

        // D-148 — le handler prévient l'instrumentiste (ENCODING_CORRECTION_REQUESTED), après
        // le flush : jamais de notification pour une transition qui n'a pas abouti.
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

    /** D-148 — motif de correction obligatoire, jamais un texte vide ou fait d'espaces. */
    private function requireReason(string $comment): string
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw new UnprocessableEntityHttpException('Le motif de la correction est obligatoire.');
        }

        return $comment;
    }

    /** @return array{fromStatus: string, toStatus: string} */
    private function transitionPayload(MissionStatus $from, Mission $mission): array
    {
        return ['fromStatus' => $from->value, 'toStatus' => $mission->getStatus()->value];
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
